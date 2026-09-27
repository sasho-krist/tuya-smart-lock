<?php

declare(strict_types=1);

use SmartLock\AlarmMonitor;
use SmartLock\Env;
use SmartLock\Mailer;
use SmartLock\TuyaClient;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__.'/../bootstrap.php';

$lock = fopen(STORAGE_DIR.'/monitor.lock', 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$client = new TuyaClient(
    baseUrl: Env::get('TUYA_BASE_URL', 'https://openapi.tuyaeu.com'),
    clientId: Env::required('TUYA_CLIENT_ID'),
    clientSecret: Env::required('TUYA_CLIENT_SECRET'),
    tokenCacheFile: STORAGE_DIR.'/tuya_token.json',
);
$monitor = new AlarmMonitor($client, STORAGE_DIR.'/monitor_state.json');
$notifyInfo = filter_var(Env::get('ALERT_DOORBELL', 'true'), FILTER_VALIDATE_BOOLEAN);
$appUrl = Env::get('APP_URL');
$debug = in_array('--debug', $argv, true);

foreach (lock_devices() as $deviceId => $label) {
    try {
        $events = $monitor->check($deviceId);
        if ($debug) {
            echo "== {$label}\nизточник: {$monitor->lastRun['source']}".($monitor->lastRun['error'] !== null ? " (лог грешка: {$monitor->lastRun['error']})" : '')."\n";
            foreach ($monitor->lastRun['reports'] as $r) {
                echo '  '.date('H:i:s', intdiv($r['time'], 1000))." {$r['code']} = ".json_encode($r['value'])."\n";
            }
            echo 'събития за имейл: '.count($events)."\n";
            foreach (is_array($monitor->lastRun['raw'] ?? null) ? ($monitor->lastRun['raw']['properties'] ?? []) : [] as $p) {
                if (is_array($p) && isset($p['code'])) {
                    echo '  [последен отчет] '.(is_numeric($p['time'] ?? null) ? date('d.m H:i:s', intdiv((int) $p['time'], 1000)) : '?')
                        ." {$p['code']} = ".json_encode($p['value'] ?? null)."\n";
                }
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, date('c')." {$label}: ".$e->getMessage()."\n");
        continue;
    }

    if (! $notifyInfo) {
        $events = array_values(array_filter($events, static fn (array $e): bool => $e['severity'] !== 'info' || ! str_contains($e['text'], 'звъни')));
    }
    if ($events === []) {
        continue;
    }

    $lines = [];
    foreach ($events as $event) {
        $lines[] = date('d.m.Y H:i:s', intdiv($event['time'], 1000)).' — '.$event['text'];
        audit_log('alert', $deviceId, $event['text']);
    }

    $top = $events[0];
    foreach ($events as $event) {
        if ($event['severity'] === 'critical') {
            $top = $event;
            break;
        }
    }

    $icon = ['critical' => '🚨', 'warning' => '⚠️', 'info' => '🔔'][$top['severity']] ?? '🔔';
    $subject = "{$icon} {$label}: {$top['text']}".(count($events) > 1 ? ' (+'.(count($events) - 1).')' : '');
    $body = "{$label}\n\n".implode("\n", $lines)."\n".($appUrl !== '' ? "\nОтвори: {$appUrl}\n" : '');

    try {
        Mailer::fromEnv()->send($subject, $body);
    } catch (Throwable $e) {
        fwrite(STDERR, date('c').' имейл: '.$e->getMessage()."\n");
    }
}

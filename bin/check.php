<?php

declare(strict_types=1);

use SmartLock\Env;
use SmartLock\SmartLockService;
use SmartLock\TuyaClient;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__.'/../bootstrap.php';

$client = new TuyaClient(
    baseUrl: Env::get('TUYA_BASE_URL', 'https://openapi.tuyaeu.com'),
    clientId: Env::required('TUYA_CLIENT_ID'),
    clientSecret: Env::required('TUYA_CLIENT_SECRET'),
    tokenCacheFile: STORAGE_DIR.'/tuya_token.json',
);

$step = static function (string $label, callable $fn): void {
    try {
        $result = $fn();
        echo "✓ {$label}\n";
        if ($result !== null) {
            echo '  '.str_replace("\n", "\n  ", (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n";
        }
    } catch (Throwable $e) {
        echo "✗ {$label}\n  ".$e->getMessage()."\n";
    }
};

echo 'Base URL: '.Env::get('TUYA_BASE_URL', 'https://openapi.tuyaeu.com')."\n";
$step('Token', static fn () => substr($client->accessToken(forceRefresh: true), 0, 6).'…');

$devices = lock_devices();
if ($devices === []) {
    echo "✗ TUYA_DEVICE_IDS е празно\n";
    exit(1);
}

foreach ($devices as $id => $label) {
    echo "\n== {$label}: '{$id}' (".strlen($id)." символа)\n";
    $step('Информация за устройството', static function () use ($client, $id): mixed {
        $info = $client->request('GET', '/v1.0/devices/'.rawurlencode($id));
        if (! is_array($info)) {
            return $info;
        }
        unset($info['status']);

        return array_diff_key($info, array_flip(['local_key', 'ip', 'lat', 'lon', 'uid', 'owner_id', 'uuid']));
    });
    $step('Статус', static fn () => $client->request('GET', '/v1.0/devices/'.rawurlencode($id).'/status'));
    $step('История: суров запис', static function () use ($client, $id): mixed {
        $end = (int) round(microtime(true) * 1000);
        $result = $client->request('GET', '/v1.0/devices/'.rawurlencode($id).'/door-lock/open-logs', [
            'page_no' => 1, 'page_size' => 1, 'start_time' => $end - 7 * 86_400_000, 'end_time' => $end,
        ]);

        return is_array($result) && is_array($result['logs'] ?? null) ? ($result['logs'][0] ?? null) : $result;
    });
    $step('История (7 дни)', static fn () => (new SmartLockService($client))->logs($id, 7, 5));
    foreach (['/v1.0/devices/{id}/door-lock/alarm-logs', '/v1.1/devices/{id}/door-lock/alarm-logs'] as $pathTemplate) {
        $step('Аларми: '.$pathTemplate, static function () use ($client, $id, $pathTemplate): mixed {
            $end = (int) round(microtime(true) * 1000);

            return $client->request('GET', str_replace('{id}', rawurlencode($id), $pathTemplate), [
                'page_no' => 1, 'page_size' => 5, 'start_time' => $end - 86_400_000, 'end_time' => $end,
            ]);
        });
    }
    $step('Ключ за криптиране на кодове (без да се показва)', static function () use ($client, $id): array {
        $ticket = $client->request('POST', '/v1.0/devices/'.rawurlencode($id).'/door-lock/password-ticket');
        $ticketKey = is_array($ticket) && is_string($ticket['ticket_key'] ?? null) ? $ticket['ticket_key'] : '';
        $raw = hex2bin($ticketKey);
        $secret = Env::required('TUYA_CLIENT_SECRET');
        $key = $raw === false ? false : openssl_decrypt($raw, 'aes-256-ecb', $secret, OPENSSL_RAW_DATA);

        return [
            'ticket_key: дължина' => strlen($ticketKey),
            'ticket_key: hex' => ctype_xdigit($ticketKey),
            'декриптиран ключ: дължина' => $key === false ? 'грешка при декриптиране' : strlen($key),
            'декриптиран ключ: само hex символи' => $key !== false && ctype_xdigit($key),
            'декриптиран ключ: само видими символи' => $key !== false && ctype_print($key),
            'secret: дължина' => strlen($secret),
        ];
    });
    $step('Постоянни кодове', static fn () => (new SmartLockService($client))->passwords($id));
    $step('Ticket за отключване (не отключва)', static function () use ($client, $id): string {
        $ticket = $client->request('POST', '/v1.0/devices/'.rawurlencode($id).'/door-lock/password-ticket');

        return is_array($ticket) && isset($ticket['ticket_id']) ? 'ticket получен' : 'неочакван отговор';
    });
}

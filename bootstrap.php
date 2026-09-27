<?php

declare(strict_types=1);

use SmartLock\Env;
use SmartLock\SmartLockService;
use SmartLock\TuyaClient;

spl_autoload_register(static function (string $class): void {
    $prefix = 'SmartLock\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

Env::load(__DIR__.'/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Europe/Sofia'));

const STORAGE_DIR = __DIR__.'/storage';

/**
 * @return array<string, string> device_id => етикет
 */
function lock_devices(): array
{
    $devices = [];
    foreach (explode(',', Env::get('TUYA_DEVICE_IDS')) as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }
        [$id, $label] = array_pad(explode(':', $entry, 2), 2, '');
        $id = trim($id);
        $devices[$id] = trim($label) !== '' ? trim($label) : $id;
    }

    return $devices;
}

function password_length(): int
{
    return max(4, min(12, (int) Env::get('TUYA_PASSWORD_LENGTH', '7')));
}

function lock_service(): SmartLockService
{
    $client = new TuyaClient(
        baseUrl: Env::get('TUYA_BASE_URL', 'https://openapi.tuyaeu.com'),
        clientId: Env::required('TUYA_CLIENT_ID'),
        clientSecret: Env::required('TUYA_CLIENT_SECRET'),
        tokenCacheFile: STORAGE_DIR.'/tuya_token.json',
        caFile: Env::get('TUYA_CA_FILE'),
    );

    return new SmartLockService($client);
}

function audit_log(string $action, string $deviceId, string $result): void
{
    $line = sprintf(
        "%s\t%s\t%s\t%s\t%s\n",
        date('c'),
        $_SERVER['REMOTE_ADDR'] ?? 'cli',
        $action,
        $deviceId,
        str_replace(["\n", "\t"], ' ', $result),
    );
    file_put_contents(STORAGE_DIR.'/audit.log', $line, FILE_APPEND | LOCK_EX);
}

<?php

declare(strict_types=1);

use SmartLock\Auth;
use SmartLock\Env;
use SmartLock\NameStore;
use SmartLock\TuyaException;

require __DIR__.'/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * @param  array<string, mixed>  $data
 */
function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

Auth::startSession();
if (! Auth::check()) {
    respond(401, ['ok' => false, 'error' => 'Не сте влезли.']);
}

$action = (string) ($_GET['action'] ?? '');
$devices = lock_devices();

if ($action === 'devices') {
    $list = [];
    foreach ($devices as $id => $label) {
        $list[] = ['id' => $id, 'label' => $label];
    }
    respond(200, [
        'ok' => true,
        'devices' => $list,
        'remote_unlock' => filter_var(Env::get('TUYA_REMOTE_UNLOCK', 'true'), FILTER_VALIDATE_BOOLEAN),
    ]);
}

$deviceId = (string) ($_GET['device'] ?? '');
if (! isset($devices[$deviceId])) {
    respond(404, ['ok' => false, 'error' => 'Непозната брава.']);
}

$isWrite = in_array($action, ['unlock', 'lock', 'temp-password', 'password-create', 'password-delete', 'name-set', 'setting-set'], true);
if ($isWrite) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'Използвайте POST.']);
    }
    if (! Auth::validCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        respond(419, ['ok' => false, 'error' => 'Невалиден CSRF токен. Презаредете страницата.']);
    }
}

/** @return array<string, mixed> */
function json_input(): array
{
    $input = json_decode((string) file_get_contents('php://input'), true);

    return is_array($input) ? $input : [];
}

$names = new NameStore(STORAGE_DIR.'/names.json');

if ($action === 'name-set') {
    $input = json_input();
    try {
        $names->set($deviceId, (string) ($input['key'] ?? ''), (string) ($input['name'] ?? ''));
    } catch (InvalidArgumentException $e) {
        respond(422, ['ok' => false, 'error' => $e->getMessage()]);
    }
    audit_log($action, $deviceId, (string) ($input['key'] ?? '').' = '.(string) ($input['name'] ?? ''));
    respond(200, ['ok' => true, 'names' => $names->all($deviceId)]);
}

try {
    $service = lock_service();

    switch ($action) {
        case 'status':
            respond(200, ['ok' => true, 'lock' => $service->status($deviceId)]);

        case 'logs':
            respond(200, ['ok' => true, 'logs' => $service->logs($deviceId), 'names' => $names->all($deviceId)]);

        case 'settings':
            respond(200, ['ok' => true] + $service->settings($deviceId));

        case 'setting-set':
            $input = json_input();
            $code = (string) ($input['code'] ?? '');
            if (preg_match('/^[a-z0-9_]{1,64}$/', $code) !== 1) {
                respond(422, ['ok' => false, 'error' => 'Невалидна настройка.']);
            }
            $service->changeSetting($deviceId, $code, $input['value'] ?? null);
            audit_log($action, $deviceId, "ok: {$code} = ".json_encode($input['value'] ?? null, JSON_UNESCAPED_UNICODE));
            respond(200, ['ok' => true, 'message' => 'Настройката е изпратена. Бравата я прилага при следващото събуждане.']);

        case 'passwords':
            respond(200, ['ok' => true, 'passwords' => $service->passwords($deviceId)]);

        case 'password-create':
            $input = json_input();
            $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
            if ($name === '') {
                respond(422, ['ok' => false, 'error' => 'Въведете име.']);
            }
            $name = mb_substr($name, 0, 30);

            $password = is_string($input['password'] ?? null) ? trim($input['password']) : '';
            if ($password === '') {
                $password = (string) random_int(1_000_000, 9_999_999);
            }
            if (preg_match('/^\d{6,10}$/', $password) !== 1) {
                respond(422, ['ok' => false, 'error' => 'Кодът трябва да е от 6 до 10 цифри.']);
            }

            $days = max(1, min(3650, (int) ($input['days'] ?? 1825)));
            $from = time();
            $id = $service->createPassword($deviceId, $name, $password, $from, $from + $days * 86400);
            audit_log($action, $deviceId, "ok: {$name}, {$days} дни, id {$id}");
            respond(200, ['ok' => true, 'password' => $password, 'id' => $id, 'valid_to' => $from + $days * 86400]);

        case 'password-delete':
            $passwordId = (string) (json_input()['id'] ?? '');
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $passwordId) !== 1) {
                respond(422, ['ok' => false, 'error' => 'Невалиден код.']);
            }
            $service->deletePassword($deviceId, $passwordId);
            audit_log($action, $deviceId, "ok: id {$passwordId}");
            respond(200, ['ok' => true]);

        case 'unlock':
        case 'lock':
            $service->operate($deviceId, $action === 'unlock');
            audit_log($action, $deviceId, 'ok');
            respond(200, ['ok' => true, 'message' => $action === 'unlock' ? 'Командата за отключване е изпратена. Ако не се отключи, събудете бравата (докоснете клавиатурата) и опитайте пак.' : 'Командата за заключване е изпратена.']);

        case 'temp-password':
            $input = json_input();
            $type = ($input['type'] ?? '') === 'multiple' ? 'multiple' : 'once';
            $hours = max(1, min(720, (int) ($input['hours'] ?? 24)));
            $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
            $name = mb_substr($name !== '' ? $name : 'Гост', 0, 30);

            $password = $service->offlinePassword($deviceId, $type, $name, $hours);
            audit_log($action, $deviceId, "ok: {$type}, {$hours}ч, {$name}");
            respond(200, ['ok' => true, 'password' => $password]);

        default:
            respond(400, ['ok' => false, 'error' => 'Непознато действие.']);
    }
} catch (TuyaException $e) {
    if ($isWrite) {
        audit_log($action, $deviceId, 'error: '.$e->getMessage());
    }
    respond(502, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[smart-lock] '.$e);
    respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}

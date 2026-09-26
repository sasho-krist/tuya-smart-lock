<?php

declare(strict_types=1);

use SmartLock\Auth;
use SmartLock\Env;
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

$isWrite = in_array($action, ['unlock', 'lock', 'temp-password'], true);
if ($isWrite) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'Използвайте POST.']);
    }
    if (! Auth::validCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        respond(419, ['ok' => false, 'error' => 'Невалиден CSRF токен. Презаредете страницата.']);
    }
}

try {
    $service = lock_service();

    switch ($action) {
        case 'status':
            respond(200, ['ok' => true, 'lock' => $service->status($deviceId)]);

        case 'logs':
            respond(200, ['ok' => true, 'logs' => $service->logs($deviceId)]);

        case 'unlock':
        case 'lock':
            $service->operate($deviceId, $action === 'unlock');
            audit_log($action, $deviceId, 'ok');
            respond(200, ['ok' => true, 'message' => $action === 'unlock' ? 'Командата за отключване е изпратена. Ако не се отключи, събудете бравата (докоснете клавиатурата) и опитайте пак.' : 'Командата за заключване е изпратена.']);

        case 'temp-password':
            $input = json_decode((string) file_get_contents('php://input'), true);
            $input = is_array($input) ? $input : [];
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

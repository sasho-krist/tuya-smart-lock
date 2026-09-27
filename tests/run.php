<?php

declare(strict_types=1);

use SmartLock\SmartLockService;
use SmartLock\TuyaClient;
use SmartLock\TuyaException;

require __DIR__.'/../bootstrap.php';

$failures = 0;
$tests = 0;

function check(string $name, bool $condition): void
{
    global $failures, $tests;
    $tests++;
    if ($condition) {
        echo "  ✓ {$name}\n";

        return;
    }
    $failures++;
    echo "  ✗ {$name}\n";
}

/**
 * @param  list<array<string, mixed>>  $responses
 * @param  list<array{method: string, url: string, headers: array<string, string>, body: string}>  $calls
 */
function fakeClient(array &$responses, array &$calls, string $cacheFile): TuyaClient
{
    return new TuyaClient(
        'https://openapi.example.test',
        'client-id',
        'secret',
        $cacheFile,
        static function (string $method, string $url, array $headers, string $body) use (&$responses, &$calls): array {
            $calls[] = compact('method', 'url', 'headers', 'body');
            $next = array_shift($responses) ?? ['success' => false, 'code' => 500, 'msg' => 'no fake response'];

            return ['status' => 200, 'body' => (string) json_encode($next)];
        },
    );
}

$tmp = sys_get_temp_dir().'/tuya-test-'.bin2hex(random_bytes(4));
mkdir($tmp);
$tokenOk = ['success' => true, 'result' => ['access_token' => 'tok-1', 'expire_time' => 7200]];

echo "Signature\n";
check('query се сортира по ключ', TuyaClient::pathWithQuery('/v1.0/x', ['b' => 2, 'a' => 1]) === '/v1.0/x?a=1&b=2');
check('stringToSign формат', TuyaClient::buildStringToSign('get', '/v1.0/token?grant_type=1', '')
    === "GET\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855\n\n/v1.0/token?grant_type=1");
$expected = strtoupper(hash_hmac('sha256', 'cidTOK1700000000000nonceSTS', 'sec'));
check('sign = HMAC(client_id+token+t+nonce+sts)', TuyaClient::sign('cid', 'sec', '1700000000000', 'nonce', 'STS', 'TOK') === $expected);

echo "Token\n";
$responses = [$tokenOk, ['success' => true, 'result' => ['online' => true]]];
$calls = [];
$client = fakeClient($responses, $calls, $tmp.'/token1.json');
$client->request('GET', '/v1.0/devices/abc');
check('първо взима token без access_token header', ! isset($calls[0]['headers']['access_token']) && str_ends_with($calls[0]['url'], '/v1.0/token?grant_type=1'));
check('бизнес заявката е с access_token', ($calls[1]['headers']['access_token'] ?? null) === 'tok-1');
$verify = TuyaClient::sign('client-id', 'secret', $calls[1]['headers']['t'], $calls[1]['headers']['nonce'], TuyaClient::buildStringToSign('GET', '/v1.0/devices/abc', ''), 'tok-1');
check('подписът на заявката е коректен', $calls[1]['headers']['sign'] === $verify);

$responses = [['success' => true, 'result' => []]];
$calls = [];
fakeClient($responses, $calls, $tmp.'/token1.json')->request('GET', '/v1.0/devices/abc/status');
check('token се взима от кеша', count($calls) === 1);

$responses = [
    ['success' => false, 'code' => 1010, 'msg' => 'token invalid'],
    ['success' => true, 'result' => ['access_token' => 'tok-2', 'expire_time' => 7200]],
    ['success' => true, 'result' => ['ok' => 1]],
];
$calls = [];
fakeClient($responses, $calls, $tmp.'/token1.json')->request('GET', '/v1.0/devices/abc');
check('при 1010 обновява token и повтаря', count($calls) === 3 && ($calls[2]['headers']['access_token'] ?? null) === 'tok-2');

echo "SmartLockService\n";
$responses = [
    $tokenOk,
    ['success' => true, 'result' => ['name' => 'Входна', 'online' => true]],
    ['success' => true, 'result' => [['code' => 'residual_electricity', 'value' => 87]]],
];
$calls = [];
$status = (new SmartLockService(fakeClient($responses, $calls, $tmp.'/token2.json')))->status('dev1');
check('status: име, онлайн и батерия', $status['name'] === 'Входна' && $status['online'] && $status['battery'] === 87);

$responses = [
    $tokenOk,
    ['success' => true, 'result' => ['ticket_id' => 'T1']],
    ['success' => true, 'result' => true],
];
$calls = [];
(new SmartLockService(fakeClient($responses, $calls, $tmp.'/token3.json')))->operate('dev1', true);
check('unlock: ticket + door-operate', str_contains($calls[2]['url'], '/password-free/door-operate')
    && json_decode($calls[2]['body'], true) === ['ticket_id' => 'T1', 'open' => true]);

$responses = [
    $tokenOk,
    ['success' => true, 'result' => ['ticket_id' => 'T1']],
    ['success' => false, 'code' => 1108, 'msg' => 'uri path invalid'],
    ['success' => true, 'result' => ['ticket_id' => 'T2']],
    ['success' => true, 'result' => true],
];
$calls = [];
(new SmartLockService(fakeClient($responses, $calls, $tmp.'/token4.json')))->operate('dev1', true);
check('unlock: fallback към open-door с нов ticket', str_contains($calls[4]['url'], '/door-lock/password-free/open-door')
    && json_decode($calls[4]['body'], true) === ['ticket_id' => 'T2']);

$responses = [
    $tokenOk,
    ['success' => true, 'result' => ['ticket_id' => 'T1']],
    ['success' => false, 'code' => 2001, 'msg' => 'device offline'],
];
$calls = [];
$thrown = null;
try {
    (new SmartLockService(fakeClient($responses, $calls, $tmp.'/token5.json')))->operate('dev1', false);
} catch (TuyaException $e) {
    $thrown = $e;
}
check('lock: грешката се хвърля без fallback', $thrown !== null && $thrown->tuyaCode === 2001 && count($calls) === 3);

$responses = [
    $tokenOk,
    ['success' => true, 'result' => ['total' => 1, 'logs' => [
        ['update_time' => 1700000000000, 'nick_name' => 'Иван', 'status' => [['code' => 'unlock_fingerprint', 'value' => 3]]],
    ]]],
];
$calls = [];
$logs = (new SmartLockService(fakeClient($responses, $calls, $tmp.'/token6.json')))->logs('dev1');
check('logs: парсване', $logs === [['time' => 1700000000000, 'user' => 'Иван', 'events' => ['unlock_fingerprint' => 3]]]);
check('logs: query е сортиран и подписан', str_contains($calls[1]['url'], '/open-logs?end_time='));

$responses = [
    $tokenOk,
    ['success' => true, 'result' => ['offline_temp_password' => '12345678', 'offline_temp_password_id' => 99]],
];
$calls = [];
$pw = (new SmartLockService(fakeClient($responses, $calls, $tmp.'/token7.json')))->offlinePassword('dev1', 'once', 'Куриер', 24, 1790000123);
$body = json_decode($calls[1]['body'], true);
check('temp password: endpoint', str_ends_with($calls[1]['url'], '/v1.1/devices/dev1/door-lock/offline-temp-password') && $calls[1]['method'] === 'POST');
check('temp password: часовете са на кръгъл час', $body === ['name' => 'Куриер', 'type' => 'once', 'effective_time' => 1789999200, 'invalid_time' => 1789999200 + 86400]);
check('temp password: резултат', $pw['password'] === '12345678' && $pw['id'] === '99');

echo "Постоянни кодове\n";
$secret = str_repeat('s', 32);
$realKey = '0123456789abcdef';
$ticketKey = bin2hex((string) openssl_encrypt($realKey, 'aes-256-ecb', $secret, OPENSSL_RAW_DATA));
$encrypted = TuyaClient::encryptWithTicketKey('1234567', $ticketKey, $secret);
check('криптиране: hex и обратимо с декриптирания ключ', ctype_xdigit($encrypted)
    && openssl_decrypt((string) hex2bin($encrypted), 'aes-128-ecb', $realKey, OPENSSL_RAW_DATA) === '1234567');

$thrown = null;
try {
    TuyaClient::encryptWithTicketKey('1234567', $ticketKey, str_repeat('x', 32));
} catch (TuyaException $e) {
    $thrown = $e;
}
check('криптиране: грешен secret дава ясна грешка', $thrown !== null);

$client = new TuyaClient('https://x.test', 'client-id', $secret, $tmp.'/token8.json', static function (string $method, string $url, array $headers, string $body) use (&$responses, &$calls): array {
    $calls[] = compact('method', 'url', 'headers', 'body');

    return ['status' => 200, 'body' => (string) json_encode(array_shift($responses))];
});
$responses = [
    $tokenOk,
    ['success' => true, 'result' => ['ticket_id' => 'T9', 'ticket_key' => $ticketKey]],
    ['success' => true, 'result' => ['id' => 321]],
];
$calls = [];
$id = (new SmartLockService($client))->createPassword('dev1', 'Иван', '1234567', 1790000000, 1790086400);
$body = json_decode($calls[2]['body'], true);
check('create: endpoint и id', str_ends_with($calls[2]['url'], '/v1.0/devices/dev1/door-lock/temp-password') && $id === '321');
check('create: body с криптирана парола', is_array($body) && $body['password'] === $encrypted && $body['password_type'] === 'ticket'
    && $body['ticket_id'] === 'T9' && $body['effective_time'] === 1790000000 && $body['invalid_time'] === 1790086400 && $body['name'] === 'Иван');

$responses = [
    ['success' => true, 'result' => [['id' => 5, 'name' => 'Иван', 'effective_time' => 1, 'invalid_time' => 2, 'phase' => 2], ['foo' => 'bar']]],
    ['success' => true, 'result' => true],
];
$calls = [];
$service = new SmartLockService($client);
check('list: парсване', $service->passwords('dev1') === [['id' => '5', 'name' => 'Иван', 'valid_from' => 1, 'valid_to' => 2, 'phase' => 2]]);
$service->deletePassword('dev1', '5');
check('delete: метод и път', $calls[1]['method'] === 'DELETE' && str_ends_with($calls[1]['url'], '/door-lock/temp-passwords/5'));

$responses = [['success' => true, 'result' => ['logs' => [['update_time' => 1, 'status' => ['code' => 'unlock_card', 'value' => 3]]]]]];
$calls = [];
check('logs: status като обект', $service->logs('dev1')[0]['events'] === ['unlock_card' => 3]);

echo "Настройки\n";
$responses = [
    ['success' => true, 'result' => ['functions' => [
        ['code' => 'beep_volume', 'type' => 'Enum', 'values' => '{"range":["mute","normal"]}'],
        ['code' => 'automatic_lock', 'type' => 'Boolean', 'values' => '{}'],
        ['code' => 'unlock_fingerprint', 'type' => 'Integer', 'values' => '{"min":0,"max":999}'],
        ['code' => 'reply_unlock_request', 'type' => 'Boolean', 'values' => '{}'],
    ]]],
    ['success' => true, 'result' => ['name' => 'L', 'online' => true]],
    ['success' => true, 'result' => [['code' => 'beep_volume', 'value' => 'normal']]],
];
$calls = [];
$settings = $service->settings('dev1');
check('settings v1: пропуска unlock_* и reply_*, добавя текуща стойност', $settings['source'] === 'v1'
    && array_column($settings['settings'], 'code') === ['beep_volume', 'automatic_lock']
    && $settings['settings'][0]['value'] === 'normal' && $settings['settings'][0]['range'] === ['mute', 'normal']);

$responses = [
    ['success' => true, 'result' => ['functions' => [['code' => 'beep_volume', 'type' => 'Enum', 'values' => '{"range":["mute","normal"]}']]]],
    ['success' => true, 'result' => true],
];
$calls = [];
$service->changeSetting('dev1', 'beep_volume', 'mute');
check('settings v1: commands body', str_ends_with($calls[1]['url'], '/v1.0/devices/dev1/commands')
    && json_decode($calls[1]['body'], true) === ['commands' => [['code' => 'beep_volume', 'value' => 'mute']]]);

$responses = [['success' => true, 'result' => ['functions' => [['code' => 'beep_volume', 'type' => 'Enum', 'values' => '{"range":["mute","normal"]}']]]]];
$calls = [];
$thrown = null;
try {
    $service->changeSetting('dev1', 'beep_volume', 'loud');
} catch (TuyaException $e) {
    $thrown = $e;
}
check('settings: невалидна стойност не се изпраща', $thrown !== null && count($calls) === 1);

$model = json_encode(['services' => [['properties' => [
    ['code' => 'language', 'accessMode' => 'rw', 'typeSpec' => ['type' => 'enum', 'range' => ['english', 'russian']]],
    ['code' => 'auto_lock_time', 'accessMode' => 'rw', 'typeSpec' => ['type' => 'value', 'min' => 1, 'max' => 60, 'step' => 1, 'unit' => 's']],
    ['code' => 'residual_electricity', 'accessMode' => 'ro', 'typeSpec' => ['type' => 'value']],
]]]]);
$responses = [
    ['success' => false, 'code' => 2009, 'msg' => 'not support this device'],
    ['success' => false, 'code' => 1108, 'msg' => 'uri path invalid'],
    ['success' => true, 'result' => ['model' => $model]],
    ['success' => true, 'result' => ['properties' => [['code' => 'language', 'value' => 'english']]]],
];
$calls = [];
$settings = $service->settings('dev1');
check('settings v2: fallback към thing model', $settings['source'] === 'v2'
    && array_column($settings['settings'], 'code') === ['language', 'auto_lock_time']
    && $settings['settings'][0]['value'] === 'english' && $settings['settings'][1]['max'] === 60);

$responses = [
    ['success' => false, 'code' => 2009, 'msg' => 'x'],
    ['success' => false, 'code' => 2009, 'msg' => 'x'],
    ['success' => true, 'result' => ['model' => $model]],
    ['success' => true, 'result' => true],
];
$calls = [];
$service->changeSetting('dev1', 'language', 'russian');
check('settings v2: shadow issue body', str_ends_with($calls[3]['url'], '/shadow/properties/issue')
    && json_decode($calls[3]['body'], true) === ['properties' => '{"language":"russian"}']);

$responses = [
    ['success' => false, 'code' => 2009, 'msg' => 'x'],
    ['success' => false, 'code' => 2009, 'msg' => 'x'],
    ['success' => false, 'code' => 1106, 'msg' => 'permission deny'],
];
$thrown = null;
try {
    $service->settings('dev1');
} catch (TuyaException $e) {
    $thrown = $e;
}
check('settings: ясна грешка, ако няма режим', $thrown !== null && str_contains($thrown->getMessage(), 'DP Instruction'));

echo "Имена\n";
$store = new SmartLock\NameStore($tmp.'/names.json');
$store->set('dev1', 'unlock_fingerprint:11', ' Иван ');
$store->set('dev1', 'unlock_card:3', 'Мария');
$store->set('dev1', 'unlock_card:3', '');
check('names: запис и изтриване', $store->all('dev1') === ['unlock_fingerprint:11' => 'Иван'] && $store->all('dev2') === []);
$thrown = null;
try {
    $store->set('dev1', '../etc', 'x');
} catch (InvalidArgumentException $e) {
    $thrown = $e;
}
check('names: невалиден ключ', $thrown !== null);

array_map('unlink', glob($tmp.'/*') ?: []);
rmdir($tmp);

echo "\n{$tests} теста, {$failures} грешки\n";
exit($failures > 0 ? 1 : 0);

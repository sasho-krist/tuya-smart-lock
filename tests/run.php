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

echo "Заявка от вратата\n";
$statusWith = static fn (int $pending): array => [
    ['success' => true, 'result' => ['name' => 'L', 'online' => true]],
    ['success' => true, 'result' => [['code' => 'unlock_request', 'value' => $pending]]],
];
$responses = [...$statusWith(25), ['success' => true, 'result' => true]];
$calls = [];
$service->replyUnlockRequest('dev1', true);
check('reply: изпраща reply_unlock_request при чакаща заявка', str_ends_with($calls[2]['url'], '/v1.0/devices/dev1/commands')
    && json_decode($calls[2]['body'], true) === ['commands' => [['code' => 'reply_unlock_request', 'value' => true]]]);

$responses = $statusWith(0);
$calls = [];
$thrown = null;
try {
    $service->replyUnlockRequest('dev1', true);
} catch (TuyaException $e) {
    $thrown = $e;
}
check('reply: без чакаща заявка не изпраща команда', $thrown !== null && count($calls) === 2);

$responses = $statusWith(18);
check('status: unlock_request', $service->status('dev1')['unlock_request'] === 18);

echo "Аларми\n";
$monitor = new SmartLock\AlarmMonitor($client, $tmp.'/monitor.json');
$responses = [['success' => true, 'result' => [['code' => 'alarm_lock', 'value' => 'wrong_finger'], ['code' => 'battery_state', 'value' => 'high']]]];
$calls = [];
check('monitor: първо пускане не праща стари аларми', $monitor->check('dev1', 1_000_000) === [] && count($calls) === 1);

$lowMonitor = new SmartLock\AlarmMonitor($client, $tmp.'/monitor-low.json');
$responses = [['success' => true, 'result' => [['code' => 'alarm_lock', 'value' => 'wrong_finger'], ['code' => 'battery_state', 'value' => 'low']]]];
$first = $lowMonitor->check('dev1', 1_000_000);
$responses = [['success' => true, 'result' => ['properties' => [['code' => 'battery_state', 'value' => 'low', 'time' => 999_000]]]]];
$lowMonitor->check('dev1', 1_000_500);
$responses = [['success' => true, 'result' => ['properties' => [['code' => 'battery_state', 'value' => 'low', 'time' => 999_000]]]]];
check('monitor: първо пускане казва за вече ниска батерия, веднъж', array_column($first, 'text') === ['Ниска батерия (low). Сменете батериите.']
    && $lowMonitor->check('dev1', 1_001_000) === []);

$monitor = new SmartLock\AlarmMonitor($client, $tmp.'/monitor-shadow.json');
$responses = [['success' => true, 'result' => [['code' => 'battery_state', 'value' => 'high']]]];
$monitor->check('dev1', 1_000_000);

$shadow = static fn (array $props): array => ['success' => true, 'result' => ['properties' => array_map(
    static fn (array $p): array => ['code' => $p[0], 'value' => $p[1], 'time' => $p[2]],
    $props,
)]];

$responses = [$shadow([['alarm_lock', 'wrong_finger', 900_000], ['battery_state', 'high', 900_000]])];
$calls = [];
check('monitor shadow: първото виждане само запомня времената', $monitor->check('dev1', 1_001_000) === []
    && str_contains($calls[0]['url'], '/v2.0/cloud/thing/dev1/shadow/properties?codes='));

$responses = [$shadow([['alarm_lock', 'wrong_finger', 1_001_500], ['hijack', true, 1_001_600], ['unlock_request', 30, 1_001_400], ['battery_state', 'low', 1_001_700]])];
$events = $monitor->check('dev1', 1_002_000);
check('monitor shadow: същата стойност с ново време = нова аларма', array_column($events, 'severity') === ['info', 'warning', 'critical', 'warning']
    && $events[1]['text'] === 'Аларма: Грешен пръстов отпечатък' && str_contains($events[2]['text'], 'заплаха'));

$responses = [$shadow([['alarm_lock', 'wrong_finger', 1_001_500], ['hijack', true, 1_001_600], ['battery_state', 'low', 1_001_700]])];
check('monitor shadow: без нови отчети няма имейл', $monitor->check('dev1', 1_003_000) === []);

$logMonitor = new SmartLock\AlarmMonitor($client, $tmp.'/monitor-logs.json');
$responses = [['success' => true, 'result' => [['code' => 'battery_state', 'value' => 'high']]]];
$logMonitor->check('dev1', 1_000_000);
$responses = [
    ['success' => false, 'code' => 1106, 'msg' => 'permission deny'],
    ['success' => true, 'result' => ['logs' => [
        ['code' => 'alarm_lock', 'value' => 'pry', 'event_time' => 1_000_500],
        ['code' => 'unlock_request', 'value' => '30', 'event_time' => 1_000_400],
        ['code' => 'unlock_request', 'value' => '0', 'event_time' => 1_000_450],
        ['code' => 'hijack', 'value' => 'true', 'event_time' => 1_000_600],
    ]]],
];
$calls = [];
$events = $logMonitor->check('dev1', 1_001_000);
check('monitor: fallback към report-logs', str_contains($calls[1]['url'], '/v2.0/cloud/thing/dev1/report-logs?codes=') && str_contains($calls[1]['url'], 'start_time=1000001')
    && array_column($events, 'severity') === ['info', 'critical', 'critical'] && $logMonitor->lastRun['source'] === 'report-logs');

$responses = [
    ['success' => false, 'code' => 1106, 'msg' => 'x'],
    ['success' => false, 'code' => 40000303, 'msg' => 'Parameter error!'],
    ['success' => true, 'result' => ['logs' => [['code' => 'alarm_lock', 'value' => 'wrong_card', 'event_time' => 1_001_500]]]],
];
$calls = [];
$events = $logMonitor->check('dev1', 1_002_000);
check('monitor: fallback към v1 logs', str_contains($calls[2]['url'], '/v1.0/devices/dev1/logs?codes=') && array_column($events, 'text') === ['Аларма: Грешна карта']);

$responses = [
    ['success' => false, 'code' => 1106, 'msg' => 'x'],
    ['success' => false, 'code' => 1106, 'msg' => 'x'],
    ['success' => false, 'code' => 40000303, 'msg' => 'Parameter error!'],
    ['success' => true, 'result' => [['code' => 'alarm_lock', 'value' => 'wrong_password'], ['code' => 'battery_state', 'value' => 'low']]],
];
$events = $logMonitor->check('dev1', 1_003_000);
check('monitor: fallback към статус', array_column($events, 'text') === ['Аларма: Грешен код', 'Ниска батерия (low). Сменете батериите.']
    && $logMonitor->lastRun['source'] === 'status' && str_contains((string) $logMonitor->lastRun['error'], 'Parameter error'));

$message = SmartLock\Mailer::buildMessage('a@x.bg', ['b@x.bg', 'c@x.bg'], 'Тема 🚨', "Ред 1\n.\nРед 3");
[$head, $encodedBody] = explode("\r\n\r\n", $message, 2);
check('mail: заглавия и UTF-8 тема', str_contains($head, "To: b@x.bg, c@x.bg") && str_contains($head, 'Subject: =?UTF-8?B?'.base64_encode('Тема 🚨').'?='));
check('mail: тялото е base64 (без проблем с точка на ред)', base64_decode(str_replace("\r\n", '', $encodedBody)) === "Ред 1\n.\nРед 3");

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

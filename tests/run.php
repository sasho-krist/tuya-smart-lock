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

array_map('unlink', glob($tmp.'/*') ?: []);
rmdir($tmp);

echo "\n{$tests} теста, {$failures} грешки\n";
exit($failures > 0 ? 1 : 0);

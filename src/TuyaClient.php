<?php

declare(strict_types=1);

namespace SmartLock;

/**
 * Tuya Cloud OpenAPI client (signature v2, HMAC-SHA256).
 *
 * @see https://developer.tuya.com/en/docs/iot/new-singnature
 */
final class TuyaClient
{
    private const TOKEN_INVALID_CODES = [1010, 1011];

    /** @var callable(string, string, array<string, string>, string): array{status: int, body: string} */
    private $transport;

    /**
     * @param  callable(string, string, array<string, string>, string): array{status: int, body: string}|null  $transport
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $tokenCacheFile,
        ?callable $transport = null,
        private readonly int $timeout = 15,
    ) {
        $this->transport = $transport ?? $this->curlTransport(...);
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $body
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): mixed
    {
        $token = $this->accessToken();

        try {
            return $this->send($method, $path, $query, $body, $token);
        } catch (TuyaException $e) {
            if (! in_array($e->tuyaCode, self::TOKEN_INVALID_CODES, true)) {
                throw $e;
            }
        }

        return $this->send($method, $path, $query, $body, $this->accessToken(forceRefresh: true));
    }

    public function accessToken(bool $forceRefresh = false): string
    {
        if (! $forceRefresh) {
            $cached = $this->readCachedToken();
            if ($cached !== null) {
                return $cached;
            }
        }

        $result = $this->send('GET', '/v1.0/token', ['grant_type' => 1], null, null);
        if (! is_array($result) || ! is_string($result['access_token'] ?? null)) {
            throw new TuyaException('Tuya: липсва access_token в отговора.');
        }

        $expiresIn = (int) ($result['expire_time'] ?? 3600);
        $this->writeCachedToken($result['access_token'], time() + max(60, $expiresIn - 120));

        return $result['access_token'];
    }

    /**
     * ticket_key е криптиран с Client Secret (AES-256-ECB, hex). Декриптираният ключ криптира паролата
     * с AES-ECB и PKCS7; резултатът е hex.
     */
    public function encryptLockPassword(string $password, string $ticketKeyHex): string
    {
        return self::encryptWithTicketKey($password, $ticketKeyHex, $this->clientSecret);
    }

    public static function encryptWithTicketKey(string $password, string $ticketKeyHex, string $secret): string
    {
        $encryptedKey = hex2bin($ticketKeyHex);
        if ($encryptedKey === false) {
            throw new TuyaException('Tuya: невалиден ticket_key.');
        }

        $key = openssl_decrypt($encryptedKey, 'aes-256-ecb', $secret, OPENSSL_RAW_DATA);
        if ($key === false || ! in_array(strlen($key), [16, 32], true)) {
            throw new TuyaException('Tuya: ticket_key не може да се декриптира. Проверете Client Secret.');
        }

        $cipher = strlen($key) === 16 ? 'aes-128-ecb' : 'aes-256-ecb';
        $encrypted = openssl_encrypt($password, $cipher, $key, OPENSSL_RAW_DATA);
        if ($encrypted === false) {
            throw new TuyaException('Tuya: паролата не може да се криптира.');
        }

        return strtoupper(bin2hex($encrypted));
    }

    public static function buildStringToSign(string $method, string $pathWithQuery, string $body): string
    {
        return strtoupper($method)."\n".hash('sha256', $body)."\n\n".$pathWithQuery;
    }

    public static function sign(string $clientId, string $secret, string $t, string $nonce, string $stringToSign, ?string $accessToken = null): string
    {
        $payload = $clientId.($accessToken ?? '').$t.$nonce.$stringToSign;

        return strtoupper(hash_hmac('sha256', $payload, $secret));
    }

    /**
     * Tuya изисква query параметрите в подписа да са сортирани по ключ.
     *
     * @param  array<string, scalar>  $query
     */
    public static function pathWithQuery(string $path, array $query): string
    {
        if ($query === []) {
            return $path;
        }

        ksort($query);
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = $key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        return $path.'?'.implode('&', $pairs);
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function send(string $method, string $path, array $query, ?array $body, ?string $accessToken): mixed
    {
        $method = strtoupper($method);
        $url = self::pathWithQuery($path, $query);
        $bodyJson = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $t = (string) (int) round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));

        $headers = [
            'client_id' => $this->clientId,
            't' => $t,
            'nonce' => $nonce,
            'sign_method' => 'HMAC-SHA256',
            'sign' => self::sign($this->clientId, $this->clientSecret, $t, $nonce, self::buildStringToSign($method, $url, $bodyJson), $accessToken),
            'Content-Type' => 'application/json',
        ];
        if ($accessToken !== null) {
            $headers['access_token'] = $accessToken;
        }

        $response = ($this->transport)($method, rtrim($this->baseUrl, '/').$url, $headers, $bodyJson);

        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded)) {
            throw new TuyaException('Tuya: невалиден отговор (HTTP '.$response['status'].').');
        }

        if (($decoded['success'] ?? false) !== true) {
            $code = (int) ($decoded['code'] ?? 0);
            $msg = is_string($decoded['msg'] ?? null) ? $decoded['msg'] : 'unknown error';

            throw new TuyaException("Tuya грешка {$code}: {$msg} [{$method} {$path}]", $code);
        }

        return $decoded['result'] ?? null;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}
     */
    private function curlTransport(string $method, string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new TuyaException('Tuya: curl_init неуспешен.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (! is_string($responseBody)) {
            throw new TuyaException('Tuya: мрежова грешка: '.$error);
        }

        return ['status' => $status, 'body' => $responseBody];
    }

    private function readCachedToken(): ?string
    {
        if (! is_file($this->tokenCacheFile)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->tokenCacheFile), true);
        if (! is_array($data) || ! is_string($data['token'] ?? null) || (int) ($data['expires_at'] ?? 0) <= time()) {
            return null;
        }

        return $data['token'];
    }

    private function writeCachedToken(string $token, int $expiresAt): void
    {
        file_put_contents(
            $this->tokenCacheFile,
            json_encode(['token' => $token, 'expires_at' => $expiresAt]),
            LOCK_EX,
        );
        @chmod($this->tokenCacheFile, 0600);
    }
}

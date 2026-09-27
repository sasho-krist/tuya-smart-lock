<?php

declare(strict_types=1);

namespace SmartLock;

final class SmartLockService
{
    public function __construct(private readonly TuyaClient $client) {}

    /**
     * @return array{id: string, name: string, online: bool, battery: int|string|null, status: array<string, mixed>}
     */
    public function status(string $deviceId): array
    {
        $info = $this->client->request('GET', '/v1.0/devices/'.rawurlencode($deviceId));
        $statusList = $this->client->request('GET', '/v1.0/devices/'.rawurlencode($deviceId).'/status');

        $status = [];
        foreach (is_array($statusList) ? $statusList : [] as $item) {
            if (is_array($item) && is_string($item['code'] ?? null)) {
                $status[$item['code']] = $item['value'] ?? null;
            }
        }

        $battery = $status['residual_electricity'] ?? $status['battery_state'] ?? $status['battery_percentage'] ?? null;

        return [
            'id' => $deviceId,
            'name' => is_array($info) && is_string($info['name'] ?? null) ? $info['name'] : $deviceId,
            'online' => is_array($info) && ($info['online'] ?? false) === true,
            'battery' => is_int($battery) || is_string($battery) ? $battery : null,
            'status' => $status,
        ];
    }

    /**
     * Отключване/заключване без парола: взима временен ticket и изпраща door-operate.
     * Ако Tuya върне грешка за door-operate, пробва по-стария open-door endpoint (само за отключване).
     */
    public function operate(string $deviceId, bool $open): void
    {
        $id = rawurlencode($deviceId);

        try {
            $this->client->request('POST', "/v1.0/smart-lock/devices/{$id}/password-free/door-operate", [], [
                'ticket_id' => $this->ticket($deviceId),
                'open' => $open,
            ]);

            return;
        } catch (TuyaException $e) {
            if (! $open || $e->tuyaCode === 0) {
                throw $e;
            }
            $primaryError = $e;
        }

        try {
            $this->client->request('POST', "/v1.0/devices/{$id}/door-lock/password-free/open-door", [], [
                'ticket_id' => $this->ticket($deviceId),
            ]);
        } catch (TuyaException $fallbackError) {
            throw new TuyaException(
                $primaryError->getMessage().' | fallback: '.$fallbackError->getMessage(),
                $fallbackError->tuyaCode,
            );
        }
    }

    /**
     * Офлайн временна парола: изчислява се в cloud-а, бравата я приема без да е онлайн.
     * Tuya изисква началото и краят да са на кръгъл час.
     *
     * @param  'once'|'multiple'  $type
     * @return array{password: string, id: string|null, type: string, valid_from: int, valid_to: int}
     */
    public function offlinePassword(string $deviceId, string $type, string $name, int $hours, ?int $now = null): array
    {
        $now ??= time();
        $from = intdiv($now, 3600) * 3600;
        $to = $from + max(1, $hours) * 3600;

        $result = $this->client->request('POST', '/v1.1/devices/'.rawurlencode($deviceId).'/door-lock/offline-temp-password', [], [
            'name' => $name,
            'type' => $type,
            'effective_time' => $from,
            'invalid_time' => $to,
        ]);

        $password = is_array($result) ? ($result['offline_temp_password'] ?? $result['password'] ?? null) : null;
        if (! is_string($password) && ! is_int($password)) {
            throw new TuyaException('Tuya: не е върната парола.');
        }

        $id = is_array($result) ? ($result['offline_temp_password_id'] ?? $result['id'] ?? null) : null;

        return [
            'password' => (string) $password,
            'id' => is_scalar($id) ? (string) $id : null,
            'type' => $type,
            'valid_from' => $from,
            'valid_to' => $to,
        ];
    }

    /**
     * @return list<array{time: int|null, user: string|null, events: array<string, mixed>}>
     */
    public function logs(string $deviceId, int $days = 7, int $limit = 30): array
    {
        $end = (int) round(microtime(true) * 1000);
        $result = $this->client->request('GET', '/v1.0/devices/'.rawurlencode($deviceId).'/door-lock/open-logs', [
            'page_no' => 1,
            'page_size' => max(1, min($limit, 100)),
            'start_time' => $end - $days * 86_400_000,
            'end_time' => $end,
        ]);

        $rows = is_array($result) && is_array($result['logs'] ?? null) ? $result['logs'] : [];

        $logs = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $statusRaw = $row['status'] ?? [];
            $statusItems = is_array($statusRaw) && isset($statusRaw['code']) ? [$statusRaw] : $statusRaw;

            $events = [];
            foreach (is_array($statusItems) ? $statusItems : [] as $s) {
                if (is_array($s) && is_string($s['code'] ?? null)) {
                    $events[$s['code']] = $s['value'] ?? null;
                }
            }

            $time = $row['update_time'] ?? $row['time'] ?? null;
            $user = $row['nick_name'] ?? $row['user_name'] ?? null;

            $logs[] = [
                'time' => is_numeric($time) ? (int) $time : null,
                'user' => is_string($user) && $user !== '' ? $user : null,
                'events' => $events,
            ];
        }

        return $logs;
    }

    /**
     * Постоянен (многократен) код. Бравата го получава при следващото си събуждане.
     */
    public function createPassword(string $deviceId, string $name, string $password, int $validFrom, int $validTo): ?string
    {
        $ticket = $this->ticketData($deviceId);
        if (! is_string($ticket['ticket_key'] ?? null)) {
            throw new TuyaException('Tuya: не е получен ticket_key за бравата.');
        }

        $result = $this->client->request('POST', '/v1.0/devices/'.rawurlencode($deviceId).'/door-lock/temp-password', [], [
            'name' => $name,
            'password' => $this->client->encryptLockPassword($password, $ticket['ticket_key']),
            'password_type' => 'ticket',
            'ticket_id' => $ticket['ticket_id'],
            'effective_time' => $validFrom,
            'invalid_time' => $validTo,
        ]);

        $id = is_array($result) ? ($result['id'] ?? null) : $result;

        return is_scalar($id) ? (string) $id : null;
    }

    /**
     * @return list<array{id: string, name: string, valid_from: int|null, valid_to: int|null, phase: int|null}>
     */
    public function passwords(string $deviceId): array
    {
        $result = $this->client->request('GET', '/v1.0/devices/'.rawurlencode($deviceId).'/door-lock/temp-passwords');
        $rows = is_array($result) && isset($result['list']) && is_array($result['list']) ? $result['list'] : $result;

        $passwords = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! is_scalar($row['id'] ?? null)) {
                continue;
            }

            $passwords[] = [
                'id' => (string) $row['id'],
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'valid_from' => is_numeric($row['effective_time'] ?? null) ? (int) $row['effective_time'] : null,
                'valid_to' => is_numeric($row['invalid_time'] ?? null) ? (int) $row['invalid_time'] : null,
                'phase' => is_numeric($row['phase'] ?? null) ? (int) $row['phase'] : null,
            ];
        }

        return $passwords;
    }

    public function deletePassword(string $deviceId, string $passwordId): void
    {
        $this->client->request('DELETE', '/v1.0/devices/'.rawurlencode($deviceId).'/door-lock/temp-passwords/'.rawurlencode($passwordId));
    }

    /**
     * Всички настройки, които бравата позволява да се променят (език, звук, автоматично заключване…),
     * с текущите им стойности. Първо пробва стандартните инструкции, после thing model (DP инструкции).
     *
     * @return array{source: string, settings: list<array{code: string, type: string, range: list<string>, min: int|null, max: int|null, step: int|null, unit: string, value: mixed}>}
     */
    public function settings(string $deviceId): array
    {
        [$source, $specs] = $this->specification($deviceId);
        $current = $source === 'v2' ? $this->shadowProperties($deviceId) : $this->status($deviceId)['status'];

        $settings = [];
        foreach ($specs as $spec) {
            $settings[] = $spec + ['value' => $current[$spec['code']] ?? null];
        }

        return ['source' => $source, 'settings' => $settings];
    }

    public function changeSetting(string $deviceId, string $code, mixed $value): void
    {
        [$source, $specs] = $this->specification($deviceId);

        $spec = null;
        foreach ($specs as $candidate) {
            if ($candidate['code'] === $code) {
                $spec = $candidate;
            }
        }
        if ($spec === null) {
            throw new TuyaException("Настройката {$code} не се поддържа от бравата.");
        }

        $value = self::normalizeSettingValue($spec, $value);
        $id = rawurlencode($deviceId);

        if ($source === 'v2') {
            $this->client->request('POST', "/v2.0/cloud/thing/{$id}/shadow/properties/issue", [], [
                'properties' => (string) json_encode([$code => $value]),
            ]);

            return;
        }

        $this->client->request('POST', "/v1.0/devices/{$id}/commands", [], [
            'commands' => [['code' => $code, 'value' => $value]],
        ]);
    }

    /**
     * @param  array{code: string, type: string, range: list<string>, min: int|null, max: int|null, step: int|null, unit: string}  $spec
     */
    public static function normalizeSettingValue(array $spec, mixed $value): bool|int|string
    {
        switch ($spec['type']) {
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);

            case 'enum':
                if (! is_string($value) || ! in_array($value, $spec['range'], true)) {
                    throw new TuyaException("Невалидна стойност за {$spec['code']}.");
                }

                return $value;

            default:
                if (! is_numeric($value)) {
                    throw new TuyaException("Невалидна стойност за {$spec['code']}.");
                }
                $int = (int) $value;
                if (($spec['min'] !== null && $int < $spec['min']) || ($spec['max'] !== null && $int > $spec['max'])) {
                    throw new TuyaException("Стойността за {$spec['code']} е извън допустимото ({$spec['min']}–{$spec['max']}).");
                }

                return $int;
        }
    }

    /**
     * @return array{0: string, 1: list<array{code: string, type: string, range: list<string>, min: int|null, max: int|null, step: int|null, unit: string}>}
     */
    private function specification(string $deviceId): array
    {
        $id = rawurlencode($deviceId);
        $errors = [];

        foreach (["/v1.0/devices/{$id}/specifications", "/v1.0/iot-03/devices/{$id}/specification"] as $path) {
            try {
                $result = $this->client->request('GET', $path);
                $specs = [];
                foreach (is_array($result) && is_array($result['functions'] ?? null) ? $result['functions'] : [] as $fn) {
                    if (! is_array($fn) || ! is_string($fn['code'] ?? null)) {
                        continue;
                    }
                    $values = is_string($fn['values'] ?? null) ? json_decode($fn['values'], true) : ($fn['values'] ?? []);
                    $spec = self::buildSpec($fn['code'], strtolower((string) ($fn['type'] ?? '')), is_array($values) ? $values : []);
                    if ($spec !== null) {
                        $specs[] = $spec;
                    }
                }
                if ($specs !== []) {
                    return ['v1', $specs];
                }
            } catch (TuyaException $e) {
                $errors[] = $e->getMessage();
            }
        }

        try {
            $result = $this->client->request('GET', "/v2.0/cloud/thing/{$id}/model");
            $model = is_array($result) && is_string($result['model'] ?? null) ? json_decode($result['model'], true) : null;

            $specs = [];
            foreach (is_array($model) && is_array($model['services'] ?? null) ? $model['services'] : [] as $service) {
                foreach (is_array($service['properties'] ?? null) ? $service['properties'] : [] as $prop) {
                    if (! is_array($prop) || ! is_string($prop['code'] ?? null) || ! in_array($prop['accessMode'] ?? '', ['rw', 'wr'], true)) {
                        continue;
                    }
                    $typeSpec = is_array($prop['typeSpec'] ?? null) ? $prop['typeSpec'] : [];
                    $spec = self::buildSpec($prop['code'], strtolower((string) ($typeSpec['type'] ?? '')), $typeSpec);
                    if ($spec !== null) {
                        $specs[] = $spec;
                    }
                }
            }
            if ($specs !== []) {
                return ['v2', $specs];
            }
        } catch (TuyaException $e) {
            $errors[] = $e->getMessage();
        }

        throw new TuyaException(
            'Бравата не предоставя настройки в текущия режим. В Tuya платформата превключете продукта на „DP Instruction“ (вижте README).'
            .($errors !== [] ? ' ['.implode(' | ', $errors).']' : ''),
        );
    }

    /**
     * @param  array<mixed>  $values
     * @return array{code: string, type: string, range: list<string>, min: int|null, max: int|null, step: int|null, unit: string}|null
     */
    private static function buildSpec(string $code, string $type, array $values): ?array
    {
        if (preg_match('/^(unlock_|reply_|remote_|alarm_|hijack|residual|battery|record|doorbell$|open_inside|closed_opened|lock_motor)/', $code) === 1) {
            return null;
        }

        $type = match ($type) {
            'boolean', 'bool' => 'bool',
            'enum' => 'enum',
            'integer', 'value' => 'int',
            default => null,
        };
        if ($type === null) {
            return null;
        }

        $range = [];
        foreach (is_array($values['range'] ?? null) ? $values['range'] : [] as $item) {
            if (is_scalar($item)) {
                $range[] = (string) $item;
            }
        }
        if ($type === 'enum' && $range === []) {
            return null;
        }

        return [
            'code' => $code,
            'type' => $type,
            'range' => $range,
            'min' => is_numeric($values['min'] ?? null) ? (int) $values['min'] : null,
            'max' => is_numeric($values['max'] ?? null) ? (int) $values['max'] : null,
            'step' => is_numeric($values['step'] ?? null) ? (int) $values['step'] : null,
            'unit' => is_string($values['unit'] ?? null) ? $values['unit'] : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shadowProperties(string $deviceId): array
    {
        $result = $this->client->request('GET', '/v2.0/cloud/thing/'.rawurlencode($deviceId).'/shadow/properties');

        $values = [];
        foreach (is_array($result) && is_array($result['properties'] ?? null) ? $result['properties'] : [] as $prop) {
            if (is_array($prop) && is_string($prop['code'] ?? null)) {
                $values[$prop['code']] = $prop['value'] ?? null;
            }
        }

        return $values;
    }

    private function ticket(string $deviceId): string
    {
        return $this->ticketData($deviceId)['ticket_id'];
    }

    /**
     * @return array{ticket_id: string, ticket_key?: mixed}
     */
    private function ticketData(string $deviceId): array
    {
        $result = $this->client->request('POST', '/v1.0/devices/'.rawurlencode($deviceId).'/door-lock/password-ticket');
        if (! is_array($result) || ! is_string($result['ticket_id'] ?? null)) {
            throw new TuyaException('Tuya: не е получен ticket_id за бравата.');
        }

        return $result;
    }
}

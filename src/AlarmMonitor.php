<?php

declare(strict_types=1);

namespace SmartLock;

/**
 * Открива нови аларми, отключване под заплаха, звънене и ниска батерия от последната проверка насам.
 * Основно чете лога на устройството (всяко събитие, дори повторено); ако той не е достъпен,
 * сравнява текущия статус с предишния.
 */
final class AlarmMonitor
{
    public const CODES = ['alarm_lock', 'hijack', 'unlock_request', 'battery_state', 'residual_electricity'];

    private const ALARMS = [
        'wrong_finger' => 'Грешен пръстов отпечатък',
        'wrong_password' => 'Грешен код',
        'wrong_card' => 'Грешна карта',
        'wrong_face' => 'Неразпознато лице',
        'tongue_bad' => 'Езикът на бравата не е заключил',
        'tongue_not_out' => 'Езикът на бравата не е излязъл',
        'too_hot' => 'Прегряване',
        'unclosed_time' => 'Вратата не е затворена',
        'pry' => 'Опит за разбиване',
        'key_in' => 'Поставен механичен ключ',
        'low_battery' => 'Ниска батерия',
        'power_off' => 'Батерията е изтощена',
        'shock' => 'Удар / вибрация',
        'defense' => 'Сработила охрана',
        'stay_alarm' => 'Някой стои пред вратата',
        'doorbell' => 'Звънене',
    ];

    /** @var array{source: string, error: string|null, reports: list<array{time: int, code: string, value: mixed}>, raw?: mixed} */
    public array $lastRun = ['source' => '', 'error' => null, 'reports' => []];

    private mixed $lastRaw = null;

    public function __construct(
        private readonly TuyaClient $client,
        private readonly string $stateFile,
    ) {}

    /**
     * @return list<array{time: int, severity: string, text: string}>
     */
    public function check(string $deviceId, ?int $nowMs = null): array
    {
        $nowMs ??= (int) round(microtime(true) * 1000);
        $state = $this->readState();
        $device = is_array($state[$deviceId] ?? null) ? $state[$deviceId] : null;

        if ($device === null) {
            // първото пускане не праща стари аларми, но казва за батерия, която вече е ниска
            $status = $this->currentStatus($deviceId);
            $batteryLow = false;
            $events = [];
            foreach (['battery_state', 'residual_electricity'] as $code) {
                if (array_key_exists($code, $status)) {
                    $event = $this->describe($code, $status[$code], $batteryLow);
                    if ($event !== null && $batteryLow) {
                        $events[] = ['time' => $nowMs] + $event;
                    }
                }
            }

            $state[$deviceId] = ['last_time' => $nowMs, 'status' => $status, 'battery_low' => $batteryLow];
            $this->lastRun = ['source' => 'first-run', 'error' => null, 'reports' => []];
            $this->writeState($state);

            return $events;
        }

        $since = (int) ($device['last_time'] ?? $nowMs);
        $errors = [];
        $reports = null;
        $source = '';

        // 1) последните стойности с времето им: нов wrong_finger сменя времето, дори стойността да е същата
        try {
            [$reports, $device['times']] = $this->reportsFromShadow($deviceId, is_array($device['times'] ?? null) ? $device['times'] : null);
            $source = 'shadow';
        } catch (TuyaException $e) {
            $errors[] = 'shadow: '.$e->getMessage();
        }

        // 2) лог на отчетите (всяко събитие поотделно)
        if ($reports === null) {
            foreach (['report-logs' => fn (): array => $this->reportsFromReportLogs($deviceId, $since, $nowMs),
                'logs' => fn (): array => $this->reportsFromLogs($deviceId, $since, $nowMs)] as $name => $fetch) {
                try {
                    $reports = $fetch();
                    $source = $name;
                    break;
                } catch (TuyaException $e) {
                    $errors[] = $name.': '.$e->getMessage();
                }
            }
        }

        // 3) сравнение със статуса от предишната проверка
        if ($reports === null) {
            $status = $this->currentStatus($deviceId);
            $previous = is_array($device['status'] ?? null) ? $device['status'] : [];
            $reports = [];
            foreach ($status as $code => $value) {
                $isBattery = in_array($code, ['battery_state', 'residual_electricity'], true);
                if ($isBattery || ($previous[$code] ?? null) !== $value) {
                    $reports[] = ['time' => $nowMs, 'code' => $code, 'value' => $value];
                }
            }
            $device['status'] = $status;
            $source = 'status';
        }

        $this->lastRun = ['source' => $source, 'error' => $errors === [] ? null : implode(' | ', $errors), 'reports' => $reports, 'raw' => $this->lastRaw];
        $lastTime = $reports === [] ? $nowMs : max($nowMs, max(array_column($reports, 'time')));

        $events = [];
        $batteryLow = (bool) ($device['battery_low'] ?? false);
        foreach ($reports as $report) {
            $event = $this->describe($report['code'], $report['value'], $batteryLow);
            if ($event !== null) {
                $events[] = ['time' => $report['time']] + $event;
            }
        }

        $device['last_time'] = $lastTime;
        $device['battery_low'] = $batteryLow;
        $state[$deviceId] = $device;
        $this->writeState($state);

        return $events;
    }

    /**
     * @return array{severity: string, text: string}|null
     */
    private function describe(string $code, mixed $value, bool &$batteryLow): ?array
    {
        switch ($code) {
            case 'alarm_lock':
                if (! is_string($value) || $value === '') {
                    return null;
                }
                $critical = in_array($value, ['pry', 'shock', 'defense', 'key_in'], true);

                return ['severity' => $critical ? 'critical' : 'warning', 'text' => 'Аларма: '.(self::ALARMS[$value] ?? $value)];

            case 'hijack':
                return $value === true
                    ? ['severity' => 'critical', 'text' => 'ВНИМАНИЕ: отключване под заплаха (hijack)!']
                    : null;

            case 'unlock_request':
                return is_numeric($value) && (int) $value > 0
                    ? ['severity' => 'info', 'text' => 'Някой звъни на вратата и чака отключване (~'.(int) $value.' сек.)']
                    : null;

            case 'battery_state':
            case 'residual_electricity':
                $low = in_array($value, ['low', 'poweroff'], true) || (is_numeric($value) && (int) $value <= 20);
                if ($low === $batteryLow) {
                    return null;
                }
                $batteryLow = $low;

                return $low
                    ? ['severity' => 'warning', 'text' => 'Ниска батерия ('.(is_scalar($value) ? (string) $value : '?').'). Сменете батериите.']
                    : ['severity' => 'info', 'text' => 'Батерията е наред.'];
        }

        return null;
    }

    /**
     * @param  array<string, int>|null  $knownTimes  код => време на последния видян отчет (null = първо пускане)
     * @return array{0: list<array{time: int, code: string, value: mixed}>, 1: array<string, int>}
     */
    private function reportsFromShadow(string $deviceId, ?array $knownTimes): array
    {
        $result = $this->client->request('GET', '/v2.0/cloud/thing/'.rawurlencode($deviceId).'/shadow/properties', [
            'codes' => implode(',', self::CODES),
        ]);

        $this->lastRaw = $result;
        $properties = is_array($result) && is_array($result['properties'] ?? null) ? $result['properties'] : null;
        if ($properties === null) {
            throw new TuyaException('shadow: неочакван отговор');
        }

        $reports = [];
        $times = $knownTimes ?? [];
        foreach ($properties as $prop) {
            if (! is_array($prop) || ! is_string($prop['code'] ?? null) || ! is_numeric($prop['time'] ?? null)) {
                continue;
            }
            $code = $prop['code'];
            $time = (int) $prop['time'];
            $isBattery = in_array($code, ['battery_state', 'residual_electricity'], true);

            if ($knownTimes !== null && ($time > ($knownTimes[$code] ?? 0) || $isBattery)) {
                $reports[] = ['time' => $time, 'code' => $code, 'value' => $prop['value'] ?? null];
            }
            $times[$code] = max($time, $times[$code] ?? 0);
        }

        usort($reports, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);

        return [$reports, $times];
    }

    /**
     * @return list<array{time: int, code: string, value: mixed}>
     */
    private function reportsFromReportLogs(string $deviceId, int $fromMs, int $toMs): array
    {
        $result = $this->client->request('GET', '/v2.0/cloud/thing/'.rawurlencode($deviceId).'/report-logs', [
            'codes' => implode(',', self::CODES),
            'start_time' => $fromMs + 1,
            'end_time' => $toMs,
            'size' => 100,
        ]);

        return self::parseLogs($result);
    }

    /**
     * @return list<array{time: int, code: string, value: mixed}>
     */
    private function reportsFromLogs(string $deviceId, int $fromMs, int $toMs): array
    {
        $result = $this->client->request('GET', '/v1.0/devices/'.rawurlencode($deviceId).'/logs', [
            'type' => 7,
            'codes' => implode(',', self::CODES),
            'start_time' => $fromMs + 1,
            'end_time' => $toMs,
        ]);

        return self::parseLogs($result);
    }

    /**
     * @return list<array{time: int, code: string, value: mixed}>
     */
    private static function parseLogs(mixed $result): array
    {
        if (! is_array($result) || ! is_array($result['logs'] ?? null)) {
            throw new TuyaException('logs: неочакван отговор');
        }

        $reports = [];
        foreach ($result['logs'] as $log) {
            if (! is_array($log) || ! is_string($log['code'] ?? null) || ! is_numeric($log['event_time'] ?? null)) {
                continue;
            }
            $value = $log['value'] ?? null;
            if ($value === 'true' || $value === 'false') {
                $value = $value === 'true';
            }
            $reports[] = ['time' => (int) $log['event_time'], 'code' => $log['code'], 'value' => $value];
        }

        usort($reports, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);

        return $reports;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentStatus(string $deviceId): array
    {
        $status = [];
        foreach ((array) $this->client->request('GET', '/v1.0/devices/'.rawurlencode($deviceId).'/status') as $item) {
            if (is_array($item) && in_array($item['code'] ?? null, self::CODES, true)) {
                $status[(string) $item['code']] = $item['value'] ?? null;
            }
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        $data = is_file($this->stateFile) ? json_decode((string) file_get_contents($this->stateFile), true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }
}

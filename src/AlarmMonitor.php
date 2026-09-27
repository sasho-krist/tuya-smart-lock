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
            $this->writeState($state);

            return $events;
        }

        try {
            $reports = $this->reportsFromLogs($deviceId, (int) ($device['last_time'] ?? $nowMs), $nowMs);
            $lastTime = $reports === [] ? $nowMs : max($nowMs, max(array_column($reports, 'time')));
        } catch (TuyaException) {
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
            $lastTime = $nowMs;
        }

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
     * @return list<array{time: int, code: string, value: mixed}>
     */
    private function reportsFromLogs(string $deviceId, int $fromMs, int $toMs): array
    {
        $result = $this->client->request('GET', '/v1.0/devices/'.rawurlencode($deviceId).'/logs', [
            'type' => 7,
            'codes' => implode(',', self::CODES),
            'start_time' => $fromMs + 1,
            'end_time' => $toMs,
            'size' => 100,
        ]);

        $reports = [];
        foreach (is_array($result) && is_array($result['logs'] ?? null) ? $result['logs'] : [] as $log) {
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

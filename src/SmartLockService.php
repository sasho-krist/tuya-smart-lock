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

            $events = [];
            foreach (is_array($row['status'] ?? null) ? $row['status'] : [] as $s) {
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

    private function ticket(string $deviceId): string
    {
        $result = $this->client->request('POST', '/v1.0/devices/'.rawurlencode($deviceId).'/door-lock/password-ticket');
        if (! is_array($result) || ! is_string($result['ticket_id'] ?? null)) {
            throw new TuyaException('Tuya: не е получен ticket_id за бравата.');
        }

        return $result['ticket_id'];
    }
}

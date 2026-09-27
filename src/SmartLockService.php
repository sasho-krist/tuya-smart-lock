<?php

declare(strict_types=1);

namespace SmartLock;

final class SmartLockService
{
    public function __construct(private readonly TuyaClient $client) {}

    /**
     * @return array{id: string, name: string, online: bool, battery: int|string|null, unlock_request: int, status: array<string, mixed>}
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
            'unlock_request' => is_numeric($status['unlock_request'] ?? null) ? max(0, (int) $status['unlock_request']) : 0,
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
     * Отговор на заявка за отключване от вратата (някой е позвънил/натиснал бутона на бравата).
     * Изпраща се само докато бравата чака (unlock_request > 0), иначе командата няма смисъл.
     */
    public function replyUnlockRequest(string $deviceId, bool $approve): void
    {
        $pending = $this->status($deviceId)['status']['unlock_request'] ?? 0;
        if (! is_numeric($pending) || (int) $pending <= 0) {
            throw new TuyaException('Няма чакаща заявка от вратата (или е изтекла).');
        }

        $this->client->request('POST', '/v1.0/devices/'.rawurlencode($deviceId).'/commands', [], [
            'commands' => [['code' => 'reply_unlock_request', 'value' => $approve]],
        ]);
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

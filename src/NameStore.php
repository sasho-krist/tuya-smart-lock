<?php

declare(strict_types=1);

namespace SmartLock;

/**
 * Локални имена за начините на отключване: "unlock_fingerprint:11" => "Иван".
 * Tuya връща в историята само номера на отпечатъка/картата/кода.
 */
final class NameStore
{
    public function __construct(private readonly string $file) {}

    /**
     * @return array<string, string>
     */
    public function all(string $deviceId): array
    {
        $data = $this->read();

        return is_array($data[$deviceId] ?? null) ? $data[$deviceId] : [];
    }

    public function set(string $deviceId, string $key, string $name): void
    {
        if (preg_match('/^unlock_[a-z_]+:\d+$/', $key) !== 1) {
            throw new \InvalidArgumentException('Невалиден ключ.');
        }

        $data = $this->read();
        $names = is_array($data[$deviceId] ?? null) ? $data[$deviceId] : [];
        $name = trim($name);

        if ($name === '') {
            unset($names[$key]);
        } else {
            $names[$key] = mb_substr($name, 0, 40);
        }

        ksort($names);
        $data[$deviceId] = $names;
        file_put_contents($this->file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (! is_file($this->file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->file), true);

        return is_array($data) ? $data : [];
    }
}

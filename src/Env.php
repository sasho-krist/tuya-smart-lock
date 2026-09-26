<?php

declare(strict_types=1);

namespace SmartLock;

use RuntimeException;

final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (! is_file($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            self::$values[trim($key)] = $value;
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $fromServer = getenv($key);
        if (is_string($fromServer) && $fromServer !== '') {
            return $fromServer;
        }

        return self::$values[$key] ?? $default;
    }

    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === '') {
            throw new RuntimeException("Липсва задължителна настройка {$key} в .env");
        }

        return $value;
    }
}

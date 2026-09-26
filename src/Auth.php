<?php

declare(strict_types=1);

namespace SmartLock;

/**
 * Защита с парола + CSRF + ограничение на неуспешните опити (файлово, по IP).
 */
final class Auth
{
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 900;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off',
        ]);
        session_start();

        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function check(): bool
    {
        return ($_SESSION['auth'] ?? false) === true;
    }

    public static function csrfToken(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    public static function validCsrf(?string $token): bool
    {
        return is_string($token) && self::csrfToken() !== '' && hash_equals(self::csrfToken(), $token);
    }

    public static function attempt(string $password, string $passwordHash, string $attemptsDir): bool
    {
        $file = $attemptsDir.'/login_'.hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')).'.json';
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $state = is_array($state) ? $state : ['count' => 0, 'first' => time()];

        if ((int) $state['first'] < time() - self::LOCKOUT_SECONDS) {
            $state = ['count' => 0, 'first' => time()];
        }
        if ((int) $state['count'] >= self::MAX_ATTEMPTS) {
            return false;
        }

        if ($passwordHash !== '' && password_verify($password, $passwordHash)) {
            @unlink($file);
            session_regenerate_id(true);
            $_SESSION['auth'] = true;

            return true;
        }

        $state['count'] = (int) $state['count'] + 1;
        file_put_contents($file, json_encode($state), LOCK_EX);

        return false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}

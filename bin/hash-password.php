<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$password = $argv[1] ?? null;
if ($password === null) {
    fwrite(STDOUT, 'Парола: ');
    $password = trim((string) fgets(STDIN));
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Паролата трябва да е поне 8 символа.\n");
    exit(1);
}

echo "APP_PASSWORD_HASH='".password_hash($password, PASSWORD_DEFAULT)."'\n";

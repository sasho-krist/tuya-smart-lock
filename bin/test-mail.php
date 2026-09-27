<?php

declare(strict_types=1);

use SmartLock\Mailer;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__.'/../bootstrap.php';

try {
    Mailer::fromEnv()->send('✅ Смарт брава: тестов имейл', "Ако четеш това, известията по имейл работят.\n");
    echo "Изпратено.\n";
} catch (Throwable $e) {
    echo 'Грешка: '.$e->getMessage()."\n";
    exit(1);
}

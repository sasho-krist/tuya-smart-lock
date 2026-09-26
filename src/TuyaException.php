<?php

declare(strict_types=1);

namespace SmartLock;

use RuntimeException;

final class TuyaException extends RuntimeException
{
    public function __construct(string $message, public readonly int $tuyaCode = 0)
    {
        parent::__construct($message, $tuyaCode);
    }
}

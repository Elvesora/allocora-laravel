<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Exceptions;

use RuntimeException;

class AllocoraException extends RuntimeException
{
    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
        ];
    }
}

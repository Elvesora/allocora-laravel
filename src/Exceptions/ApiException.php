<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Exceptions;

class ApiException extends AllocoraException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $errorCode,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'status_code' => $this->statusCode,
            'error_code' => $this->errorCode,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'request_id' => $this->requestId,
        ];
    }
}

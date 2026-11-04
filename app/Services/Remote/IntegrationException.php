<?php

namespace App\Services\Remote;

use RuntimeException;

class IntegrationException extends RuntimeException
{
    public function __construct(
        string $message = 'Integración pendiente de implementar.',
        public readonly int $status = 503,
        public readonly string $errorCode = 'INTEGRATION_NOT_READY',
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    // Do not log exception bodies, credentials or remote URLs.
    public function report(): bool
    {
        return true;
    }

    public function render()
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(), 'code' => $this->errorCode,
            'errors' => $this->errors ?: null,
        ], fn ($value) => $value !== null), $this->status);
    }
}

<?php

namespace App\Architecture;

use InvalidArgumentException;

final class ArchitectureMode
{
    public function __construct(public readonly string $value)
    {
        if (! in_array($value, ['local', 'microservices'], true)) {
            throw new InvalidArgumentException('APP_ARCHITECTURE debe ser local o microservices.');
        }
    }

    public function isLocal(): bool
    {
        return $this->value === 'local';
    }
}

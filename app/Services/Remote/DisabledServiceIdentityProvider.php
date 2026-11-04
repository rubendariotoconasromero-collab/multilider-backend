<?php

namespace App\Services\Remote;

use App\Contracts\Architecture\ServiceIdentityProvider;

final class DisabledServiceIdentityProvider implements ServiceIdentityProvider
{
    public function forOperation(string $service, string $operation): string
    {
        throw new IntegrationException('No hay identidad técnica autorizada para esta operación.');
    }
}

<?php

namespace App\Services\Remote;

use App\Contracts\Architecture\CommercialGateway;
use App\Contracts\Architecture\FinanceGateway;
use App\Contracts\Architecture\IdentityGateway;

final class PendingDomainGateway implements CommercialGateway, FinanceGateway, IdentityGateway
{
    public function currentUser(): array
    {
        throw new IntegrationException('Identidad remota pendiente de fase 3.');
    }

    public function sale(int $id): array
    {
        throw new IntegrationException('Comercial remoto pendiente de fase 5.');
    }

    public function payment(int $id): array
    {
        throw new IntegrationException('Finanzas remoto pendiente de fases 5 y 6.');
    }
}

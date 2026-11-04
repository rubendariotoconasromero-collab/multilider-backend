<?php

namespace App\Services\Remote;

use App\Contracts\Architecture\CommercialGateway;
use App\Contracts\Architecture\FinanceGateway;

final class PendingDomainGateway implements CommercialGateway, FinanceGateway
{
    public function sale(int $id): array
    {
        throw new IntegrationException('Comercial remoto pendiente de fase 5.');
    }

    public function payment(int $id): array
    {
        throw new IntegrationException('Finanzas remoto pendiente de fases 5 y 6.');
    }
}

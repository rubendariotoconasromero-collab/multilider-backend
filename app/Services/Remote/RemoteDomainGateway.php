<?php

namespace App\Services\Remote;

use App\Contracts\Architecture\CommercialGateway;
use App\Contracts\Architecture\FinanceGateway;

final class RemoteDomainGateway implements CommercialGateway, FinanceGateway
{
    public function __construct(private BusinessGateway $business) {}
    public function sale(int $id): array
    {
        return $this->business->send(request(), 'comercial', 'GET', 'api/ventas/'.$id)['body'];
    }
    public function payment(int $id): array
    {
        return $this->business->send(request(), 'finanzas', 'GET', 'api/pagos/'.$id)['body'];
    }
}

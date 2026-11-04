<?php

namespace App\Services\Local;

use App\Contracts\Architecture\CommercialGateway;
use App\Contracts\Architecture\FinanceGateway;
use App\Models\NotaVenta;
use App\Models\Pago;

final class LocalDomainGateway implements CommercialGateway, FinanceGateway
{
    public function sale(int $id): array
    {
        return NotaVenta::with(['asesor', 'cliente', 'propiedad', 'planPago.cuotas', 'pagos'])->findOrFail($id)->toArray();
    }

    public function payment(int $id): array
    {
        return Pago::with(['notaVenta', 'metodoPago', 'cuenta'])->findOrFail($id)->toArray();
    }
}

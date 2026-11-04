<?php

namespace App\Services\Local;

use App\Contracts\Architecture\CommercialGateway;
use App\Contracts\Architecture\FinanceGateway;
use App\Contracts\Architecture\IdentityGateway;
use App\Models\NotaVenta;
use App\Models\Pago;
use Illuminate\Auth\AuthenticationException;

final class LocalDomainGateway implements CommercialGateway, FinanceGateway, IdentityGateway
{
    public function currentUser(): array
    {
        $user = auth()->user();
        if (! $user) {
            throw new AuthenticationException;
        }

        return $user->load(['rolesPermisos.rolPermiso.rol', 'rolesPermisos.rolPermiso.permiso'])->toArray();
    }

    public function sale(int $id): array
    {
        return NotaVenta::with(['asesor', 'cliente', 'propiedad', 'planPago.cuotas', 'pagos'])->findOrFail($id)->toArray();
    }

    public function payment(int $id): array
    {
        return Pago::with(['notaVenta', 'metodoPago', 'cuenta'])->findOrFail($id)->toArray();
    }
}

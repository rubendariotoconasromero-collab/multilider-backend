<?php

namespace App\Services\Local;

use App\Contracts\Architecture\CatalogGateway;
use App\Models\Propiedad;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

final class LocalCatalogGateway implements CatalogGateway
{
    public function properties(Request $request): LengthAwarePaginator
    {
        $query = Propiedad::with(['propietarios', 'sectorUrbano.distrito.ciudad', 'ubicacion', 'caracteristicas', 'imagenes']);
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('codigo', 'LIKE', "%{$search}%")
                    ->orWhereHas('propietarios', fn ($s) => $s->where('nombre_completo', 'LIKE', "%{$search}%")
                        ->orWhere('nombre_empresa', 'LIKE', "%{$search}%"));
            });
        }
        if ($request->filled('ciudad_id')) {
            $query->whereHas('sectorUrbano.distrito', fn ($q) => $q->where('ciudad_id', $request->ciudad_id));
        }
        if ($request->filled('distrito_id')) {
            $query->whereHas('sectorUrbano', fn ($q) => $q->where('distrito_id', $request->distrito_id));
        }

        return $query->orderBy('id', 'desc')->paginate($request->input('per_page', 10));
    }
}

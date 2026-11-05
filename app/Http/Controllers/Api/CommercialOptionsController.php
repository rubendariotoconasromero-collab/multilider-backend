<?php

namespace App\Http\Controllers\Api;

use App\Architecture\ArchitectureMode;
use App\Http\Controllers\Controller;
use App\Models\Asesor;
use App\Models\Cliente;
use App\Models\CuentaBancaria;
use App\Models\MetodoPago;
use App\Models\Propiedad;
use App\Services\Remote\BusinessGateway;
use Illuminate\Http\Request;

final class CommercialOptionsController extends Controller
{
    public function index(Request $request, ArchitectureMode $mode)
    {
        if (! $mode->isLocal()) {
            $gateway = app(BusinessGateway::class);
            $commercial = $gateway->send($request, 'comercial', 'GET', 'api/comercial/opciones')['body'];
            $finance = $gateway->send($request, 'finanzas', 'GET', 'api/finanzas/opciones')['body'];
            $properties = app(\App\Services\Remote\CatalogClient::class)->request($request, 'GET', 'api/catalogo/opciones-venta')['body'];
            return response()->json($commercial + $finance + ['propiedades' => $properties['data'] ?? $properties]);
        }
        $search = $request->string('search')->toString();
        return response()->json([
            'clientes' => Cliente::where('estado', true)->when($search, fn ($q) => $q->where('nombre_completo', 'like', '%'.$search.'%'))
                ->orderBy('id')->limit(100)->get(['id', 'nombre_completo', 'ci', 'estado', 'user_id']),
            'asesores' => Asesor::where('estado', true)->orderBy('id')->limit(100)->get(['id', 'nombre_completo', 'porcentaje_comision', 'estado', 'user_id']),
            'propiedades' => Propiedad::with('sectorUrbano.distrito.ciudad')->where('activo', true)->where('estado', 'Disponible')
                ->orderBy('id')->limit(100)->get(),
            'metodos_pago' => MetodoPago::where('estado', 'Activo')->get(),
            'cuentas_bancarias' => CuentaBancaria::where('estado', 'Activa')->get(),
        ]);
    }
}

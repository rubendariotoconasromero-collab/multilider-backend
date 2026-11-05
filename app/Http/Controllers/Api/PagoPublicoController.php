<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\NotaVenta;
use App\Models\Pago;
use Illuminate\Http\Request;
use App\Services\InstallmentBalances;

class PagoPublicoController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // PASO 1 → Buscar cliente por CI y retornar sus pagos pendientes
    // ─────────────────────────────────────────────────────────────────────────
    public function buscarCliente(Request $request)
    {
        $request->validate(['ci' => 'required|string|max:20']);

        $cliente = Cliente::where('ci', $request->ci)->where('estado', true)->first();

        if (!$cliente) {
            return response()->json([
                'found'   => false,
                'message' => 'No se encontró ningún cliente registrado con ese número de CI.',
            ], 404);
        }

        $ventaIds = NotaVenta::where('cliente_id', $cliente->id)
            ->whereIn('estado', ['Activa', 'Completada'])
            ->pluck('id');

        // Pagos de contado o cuota inicial pendientes de pago
        $pagosContado = Pago::whereIn('nota_venta_id', $ventaIds)
            ->whereIn('concepto_pago', ['VENTA_CONTADO', 'CUOTA_INICIAL'])
            ->where('estado', 'PENDIENTE_PAGO')->where('monto', '>', 0)
            ->with(['notaVenta.propiedad:id,codigo,tipo,moneda'])
            ->get()
            ->map(fn($p) => [
                'id'          => $p->id,
                'tipo'        => $p->concepto_pago === 'VENTA_CONTADO' ? 'Pago al Contado' : 'Cuota Inicial',
                'monto'       => $p->monto,
                'moneda'      => $p->notaVenta->propiedad->moneda ?? 'Bs',
                'propiedad'   => $p->notaVenta->propiedad->codigo ?? '-',
                'tipo_prop'   => $p->notaVenta->propiedad->tipo ?? '-',
                'nota_venta_id' => $p->nota_venta_id,
            ]);

        // Cuotas de planes de crédito pendientes
        $cuotasPendientes = Cuota::whereHas('planPago.notaVenta', function ($q) use ($cliente) {
                $q->where('cliente_id', $cliente->id)->whereIn('estado', ['Activa', 'Completada']);
            })
            ->whereIn('estado', ['Pendiente', 'Vencida'])
            ->withSum(['pagos as cobrado' => fn ($q) => $q->where('estado', 'PAGADO')->where('concepto_pago', 'CUOTA')], 'monto')
            ->with(['planPago.notaVenta.propiedad:id,codigo,tipo,moneda'])
            ->orderBy('fecha_vencimiento')
            ->get()
            ->map(fn($c) => [
                'cuota_id'          => $c->id,
                'numero_cuota'      => $c->numero_cuota,
                'fecha_vencimiento' => $c->fecha_vencimiento,
                'monto_cuota'       => InstallmentBalances::calculate($c, (float) $c->cobrado)['monto_pendiente'],
                'moneda'            => $c->planPago->notaVenta->propiedad->moneda ?? 'Bs',
                'propiedad'         => $c->planPago->notaVenta->propiedad->codigo ?? '-',
                'tipo_prop'         => $c->planPago->notaVenta->propiedad->tipo ?? '-',
                'nota_venta_id'     => $c->planPago->nota_venta_id,
                'plan_pago_id'      => $c->plan_pago_id,
            ])->filter(fn ($quota) => $quota['monto_cuota'] > 0)->values();

        return response()->json([
            'found'            => true,
            'cliente'          => $cliente->only(['id', 'nombre_completo', 'ci', 'telefono', 'correo']),
            'pagos_contado'    => $pagosContado,
            'cuotas_pendientes' => $cuotasPendientes,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PASO 3 → Procesar pago: crear transacción en Libélula
    // ─────────────────────────────────────────────────────────────────────────
    public function procesarPago(Request $request)
    {
        return app(\App\Services\LocalPaymentTests::class)->create($request);
    }

    public function verificarEstado($transaccionId)
    {
        return app(\App\Services\LocalPaymentTests::class)->status($transaccionId);
    }

    public function confirmarRetorno(Request $request)
    {
        $request->validate(['id_transaccion' => 'required|string|max:80']);
        return app(\App\Services\LocalPaymentTests::class)->status($request->input('id_transaccion'));
    }

    public function callbackLibelula(Request $request)
    {
        return app(\App\Services\LocalPaymentTests::class)->callback($request);
    }
}

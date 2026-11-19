<?php

namespace App\Services;

use App\Architecture\ArchitectureMode;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Pago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LocalPaymentTests
{
    public function __construct(private LibelulaGateway $provider, private ArchitectureMode $mode) {}
    private function local(): void
    {
        if (! $this->mode->isLocal()) { throw new \LogicException('No consultar pagos locales en modo remoto.'); }
    }
    public function create(Request $request)
    {
        $this->local();
        $this->provider->ensureReady();
        $data = $request->validate(['operacion_id' => 'required|uuid', 'cliente_id' => 'required|integer|min:1',
            'pago_ids' => 'nullable|array|max:50', 'pago_ids.*' => 'integer|min:1', 'cuota_ids' => 'nullable|array|max:50', 'cuota_ids.*' => 'integer|min:1',
            'ci_pagador' => 'required|string|max:20', 'telefono_pagador' => 'required|string|max:20',
            'nombres_pagador' => 'required|string|max:100', 'apellidos_pagador' => 'required|string|max:100', 'correo_pagador' => 'required|email|max:150']);
        $customer = Cliente::findOrFail($data['cliente_id']);
        $data['pago_ids'] ??= []; $data['cuota_ids'] ??= [];
        sort($data['pago_ids']); sort($data['cuota_ids']);
        $hash = hash('sha256', json_encode($data));
        [$order, $dispatch] = DB::transaction(function () use ($data, $hash) {
            DB::table('business_command_mutex')->where('id', 1)->lockForUpdate()->first();
            $order = DB::table('payment_test_orders')->where('id', $data['operacion_id'])->first();
            if ($order) { abort_unless($order->request_hash === $hash, 409, 'La operación identifica otros datos.'); return [$order, false]; }
            $selection = [];
            foreach (array_unique($data['pago_ids'] ?? []) as $id) {
                $p = Pago::with('notaVenta.propiedad')->lockForUpdate()->findOrFail($id);
                abort_unless((int) $p->notaVenta->cliente_id === (int) $data['cliente_id'] && in_array($p->notaVenta->estado, ['Activa', 'Completada'], true) && $p->estado === 'PENDIENTE_PAGO' && $p->monto > 0, 409);
                $selection[] = ['pago_id' => $p->id, 'venta_id' => $p->nota_venta_id, 'monto_real' => $p->monto, 'moneda_real' => $p->notaVenta->propiedad->moneda];
            }
            foreach (array_unique($data['cuota_ids'] ?? []) as $id) {
                $q = Cuota::with('planPago.notaVenta.propiedad')->lockForUpdate()->findOrFail($id);
                abort_unless((int) $q->planPago->notaVenta->cliente_id === (int) $data['cliente_id'] && in_array($q->planPago->notaVenta->estado, ['Activa', 'Completada'], true) && in_array($q->estado, ['Pendiente', 'Vencida'], true), 409);
                $remaining = InstallmentBalances::forPlan($q->planPago, collect([$q]))[$q->id]['monto_pendiente'];
                abort_unless($remaining > 0, 409, 'La cuota no tiene saldo pendiente.');
                $selection[] = ['cuota_id' => $q->id, 'venta_id' => $q->planPago->nota_venta_id, 'monto_real' => $remaining, 'moneda_real' => $q->planPago->notaVenta->propiedad->moneda];
            }
            abort_unless(count($selection) > 0 && count($selection) <= 50, 422, 'Selecciona entre una y cincuenta obligaciones pendientes.');
            abort_if(DB::table('payment_test_obligations as obligation')->join('payment_test_orders as payment_order', 'payment_order.id', '=', 'obligation.order_id')
                ->whereIn('obligation.venta_id', array_unique(array_column($selection, 'venta_id')))->where('payment_order.state', '!=', 'PagadaPrueba')->exists(),
                409, 'Ya existe una orden pendiente para esta venta. Consulta su referencia antes de volver a registrar.');
            DB::table('payment_test_orders')->insert(['id' => $data['operacion_id'], 'reference' => 'TEMP-'.$data['operacion_id'], 'request_hash' => $hash,
                'cliente_id' => $data['cliente_id'], 'state' => 'Registrando', 'amount' => count($selection) * 0.20, 'currency' => 'BOB',
                'selection' => json_encode($selection), 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_unique(array_column($selection, 'venta_id')) as $sale) {
                DB::table('payment_test_obligations')->insert(['order_id' => $data['operacion_id'], 'venta_id' => $sale]);
            }
            return [DB::table('payment_test_orders')->where('id', $data['operacion_id'])->first(), true];
        });
        if ($dispatch) {
            try {
                $name = trim($data['nombres_pagador'].' '.$data['apellidos_pagador']);
                $lines = array_map(fn ($r) => ['concepto' => '[PRUEBA] Obligación seleccionada; no liquida deuda real', 'costo_unitario' => 0.20, 'cantidad' => 1], json_decode($order->selection, true));
                $registered = $this->provider->register(['email_cliente' => $customer->correo ?? $data['correo_pagador'],
                    'identificador' => $order->reference, 'callback_url' => rtrim(config('services.libelula.callback_base_url'), '/').'/api/public/pagos/callback?identificador='.urlencode($order->reference),
                    'url_retorno' => rtrim(config('services.libelula.frontend_url'), '/').'/pagar?txn='.urlencode($order->reference),
                    'descripcion' => 'Prueba de pago; deuda real permanece pendiente', 'nombre_cliente' => $name,
                    'lineas_detalle_deuda' => $lines, 'emite_factura' => 0, 'codigo_tipo_documento' => '5', 'razon_social' => $name, 'nit' => $data['ci_pagador']]);
                DB::table('payment_test_orders')->where('id', $order->id)->update(['provider_id' => $registered['id_transaccion'] ?? null,
                    'payment_url' => $registered['url_pasarela_pagos'], 'qr_url' => $registered['qr_simple_url'] ?? null,
                    'state' => DB::raw("CASE WHEN state = 'PagadaPrueba' THEN state ELSE 'Registrada' END"), 'updated_at' => now()]);
            } catch (\Throwable) {
                DB::table('payment_test_orders')->where('id', $order->id)->where('state', 'Registrando')->update(['state' => 'RegistroIncierto', 'updated_at' => now()]);
            }
        }
        $order = DB::table('payment_test_orders')->where('id', $order->id)->first();
        return response()->json($this->dto($order), $order->payment_url ? 200 : 202);
    }
    public function callback(Request $request)
    {
        $this->local();
        abort_if($request->isMethod('HEAD'), 405, 'Usa GET o POST para la notificación.', ['Allow' => 'GET, POST']);
        $notification = validator([
            'identificador' => $request->query('identificador', $request->input('identificador')),
            'transaction_id' => $request->input('transaction_id'),
        ], [
            'identificador' => 'nullable|required_without:transaction_id|string|max:255',
            'transaction_id' => 'nullable|required_without:identificador|string|max:255',
        ])->validate();
        $reference = $notification['identificador'] ?? null;
        $provider = $notification['transaction_id'] ?? null;
        $error = $request->input('error');
        $cancel = $request->input('cancel_order');
        abort_if(($error !== null && !is_scalar($error)) || ($cancel !== null && !is_scalar($cancel)), 422);
        $id = hash('sha256', json_encode([$reference, $provider, (string) $error, (string) $cancel]));
        DB::table('payment_test_inbox')->insertOrIgnore(['id' => $id, 'reference' => $reference, 'provider_id' => $provider, 'state' => 'Pendiente', 'created_at' => now(), 'updated_at' => now()]);
        if ($reference || $provider) { $this->reconcile($reference, $provider); }
        return response()->json(['ok' => true, 'es_prueba' => true])->header('Cache-Control', 'private, no-store');
    }
    public function status(string $reference)
    {
        $this->local();
        $this->reconcile($reference);
        $order = DB::table('payment_test_orders')->where('reference', $reference)->first();
        abort_unless($order, 404);
        return response()->json($this->dto($order));
    }
    public function reconcile(?string $reference = null, ?string $providerId = null): int
    {
        $this->local();
        $query = DB::table('payment_test_orders')->whereIn('state', ['Registrando', 'Registrada', 'RegistroIncierto']);
        if ($reference) { $query->where('reference', $reference); }
        elseif ($providerId) { $query->where('provider_id', $providerId); }
        $count = 0;
        foreach ($query->orderBy('updated_at')->limit(10)->get() as $order) {
            try {
                if (! $this->provider->verify($order->reference, $order->currency, number_format((float) $order->amount, 2, '.', ''), $order->created_at)) { continue; }
                DB::transaction(function () use ($order) {
                    DB::table('payment_test_orders')->where('id', $order->id)->lockForUpdate()->first();
                    DB::table('payment_test_orders')->where('id', $order->id)->update(['state' => 'PagadaPrueba', 'updated_at' => now()]);
                    DB::table('payment_test_inbox')->where('reference', $order->reference)->update(['state' => 'Conciliada', 'updated_at' => now()]);
                    // Deliberately no update of pagos/cuotas/saldos and no full-debt ingreso.
                });
                $count++;
            } catch (\Throwable) { /* durable order remains pending; no blind registration retry */ }
            finally { DB::table('payment_test_orders')->where('id', $order->id)->update(['updated_at' => now()]); }
        }
        return $count;
    }
    private function dto(object $order): array
    {
        return ['id_transaccion' => $order->reference, 'operacion_id' => $order->id, 'url_pago' => $order->payment_url, 'qr_url' => $order->qr_url,
            'estado' => $order->state === 'PagadaPrueba' ? 'PAGADO_PRUEBA' : 'PENDIENTE_PAGO', 'es_prueba' => true,
            'monto_prueba' => number_format((float) $order->amount, 2, '.', ''), 'deuda_real_liquidada' => false];
    }
}

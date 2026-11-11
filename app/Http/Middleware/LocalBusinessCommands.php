<?php

namespace App\Http\Middleware;

use App\Architecture\ArchitectureMode;
use App\Architecture\BusinessRoutes;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LocalBusinessCommands
{
    public function __construct(private ArchitectureMode $mode) {}

    public function handle(Request $request, Closure $next)
    {
        if (! $this->mode->isLocal() || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            || ! ($route = BusinessRoutes::describe($request))) {
            return $next($request);
        }
        $id = $request->header('Idempotency-Key', $request->input('command_id'));
        $operation = $request->input('operacion_id', $id);
        if ($id !== null && (! Str::isUuid($id) || ! Str::isUuid($operation))) {
            return response()->json(['message' => 'Usa UUID válidos para la operación y el comando.'], 422);
        }
        $files = [];
        foreach ($request->allFiles() as $name => $file) {
            if (is_array($file) || ! $file->isValid()) { return response()->json(['message' => 'Archivo inválido.'], 422); }
            $files[$name] = ['sha256' => hash_file('sha256', $file->getRealPath()), 'length' => $file->getSize()];
        }
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode(self::canonical(['body' => $request->except(array_keys($files)), 'files' => $files]), JSON_UNESCAPED_UNICODE));
        $key = $id ? hash('sha256', 'local|'.$request->user()->getAuthIdentifier().'|'.$id) : null;
        $level = DB::transactionLevel();
        try { return DB::transaction(function () use ($request, $next, $key, $hash, $id, $operation) {
            if (! DB::table('business_command_mutex')->where('id', 1)->lockForUpdate()->first()) {
                throw new \RuntimeException('Falta aplicar la migración de recibos de negocio.');
            }
            if ($key && ($old = DB::table('business_commands')->where('key', $key)->first())) {
                if ($old->request_hash !== $hash) {
                    return response()->json(['message' => 'El comando ya identifica otros datos.'], 409);
                }
                return response($old->result, $old->status)->header('Content-Type', 'application/json')->header('Idempotency-Replayed', 'true');
            }
            if ($request->isMethod('PUT') && preg_match('~^api/ventas/([1-9][0-9]*)/anular$~D', $request->path(), $m)) {
                $sale = \App\Models\NotaVenta::lockForUpdate()->findOrFail($m[1]);
                $paid = \App\Models\Pago::where('nota_venta_id', $sale->id)->whereIn('estado', ['PAGADO', 'Registrado'])->exists();
                $commission = \App\Models\Egreso::where('nota_venta_id', $sale->id)->where('estado', 'PAGADO')->exists();
                $delivered = \App\Models\Entrega::whereHas('contrato', fn ($q) => $q->where('nota_venta_id', $sale->id))->where('estado', 'Entregado')->exists();
                $external = \App\Models\Pago::where('nota_venta_id', $sale->id)->where('estado', 'PENDIENTE_PAGO')->whereNotNull('id_transaccion_libelula')->exists();
                $external = $external || DB::table('payment_test_obligations as obligation')->join('payment_test_orders as payment_order', 'payment_order.id', '=', 'obligation.order_id')
                    ->where('obligation.venta_id', $sale->id)->where('payment_order.state', '!=', 'PagadaPrueba')->exists();
                if ($paid || $commission || $delivered || $external) {
                    $response = response()->json(['message' => 'La venta tiene cobros, desembolsos, entrega o un pago externo pendiente. Requiere resolución antes de anular.'], 409);
                    if ($key) { $this->receipt($request, $response, $key, $hash, $id, $operation); }
                    return $response;
                }
            }
            $this->protectState($request);
            $response = $next($request);
            // Roll back every partial write on a controller's error response as well as exceptions.
            if ($response->getStatusCode() >= 400) {
                throw new LocalCommandRejected($response);
            }
            if ($key && $response instanceof \Illuminate\Http\JsonResponse) {
                $this->receipt($request, $response, $key, $hash, $id, $operation);
            }
            return $response;
        }); } finally { while (DB::transactionLevel() > $level) { DB::rollBack(); } }
    }

    private function receipt(Request $request, \Illuminate\Http\JsonResponse $response, string $key, string $hash, string $id, string $operation): void
    {
        DB::table('business_commands')->insert(['key' => $key, 'operation_id' => $operation, 'command_id' => $id,
            'actor_id' => $request->user()->getAuthIdentifier(), 'request_hash' => $hash,
            'status' => $response->getStatusCode(), 'result' => $response->getContent(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function protectState(Request $request): void
    {
        $path = $request->path();
        if (preg_match('~^api/pagos/([1-9][0-9]*)$~D', $path, $m)) {
            $payment = \App\Models\Pago::lockForUpdate()->findOrFail($m[1]);
            abort_if($request->isMethod('DELETE'), 409, 'Conserva el recibo y usa el flujo de reversión cuando corresponda.');
            foreach (['estado', 'concepto_pago', 'monto', 'fecha_pago'] as $field) {
                if ($request->has($field) && (string) $request->input($field) !== (string) $payment->getRawOriginal($field)) {
                    abort(409, 'Los importes y estados se modifican desde el flujo de cobro o reversión.');
                }
            }
        }
        if (preg_match('~^api/contratos/([1-9][0-9]*)/(gestionar|anular)$~D', $path, $m)) {
            $contract = \App\Models\Contrato::with('notaVenta')->lockForUpdate()->findOrFail($m[1]);
            abort_unless(in_array($contract->notaVenta->estado, ['Activa', 'Completada'], true), 409, 'La venta no está operativa.');
            if ($m[2] === 'anular' || ! $request->boolean('firmado')) {
                abort_if(\App\Models\Entrega::where('contrato_id', $contract->id)->where('estado', 'Entregado')->exists(), 409, 'La entrega ya se completó.');
            }
        }
        if (preg_match('~^api/entregas/([1-9][0-9]*)(?:/subir-acta)?$~D', $path, $m)) {
            $delivery = \App\Models\Entrega::with('contrato.notaVenta')->lockForUpdate()->findOrFail($m[1]);
            abort_unless($delivery->estado !== 'Anulada' && $delivery->contrato->estado === 'Firmado'
                && in_array($delivery->contrato->notaVenta->estado, ['Activa', 'Completada'], true), 409, 'La entrega requiere una venta vigente y un contrato firmado.');
        }
        if (preg_match('~^api/egresos/([1-9][0-9]*)/anular$~D', $path, $m)) {
            abort_if(\App\Models\Egreso::whereKey($m[1])->where('estado', 'PAGADO')->exists(), 409, 'El desembolso requiere una reversión autorizada.');
        }
        if ($request->isMethod('PUT') && preg_match('~^api/(ingresos|egresos)/([1-9][0-9]*)$~D', $path, $m)) {
            $model = $m[1] === 'ingresos' ? \App\Models\Ingreso::class : \App\Models\Egreso::class;
            $movement = $model::lockForUpdate()->findOrFail($m[2]);
            if ($movement->origen === 'AUTOMATICO') {
                foreach (['fecha', 'concepto', 'categoria', 'moneda', 'asesor_id'] as $field) {
                    abort_if($request->exists($field) && (string) $request->input($field) !== (string) $movement->getRawOriginal($field),
                        409, 'Los movimientos automáticos conservan los datos de la operación que los generó.');
                }
                $amount = $request->input('monto');
                abort_if($request->exists('monto') && (! is_numeric($amount) || (float) $amount !== (float) $movement->monto),
                    409, 'Los movimientos automáticos conservan el importe de la operación que los generó.');
            }
        }
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) { ksort($value); }
        foreach ($value as &$item) { if (is_array($item)) { $item = self::canonical($item); } }
        return $value;
    }
}

final class LocalCommandRejected extends \RuntimeException
{
    public function __construct(public readonly mixed $response) { parent::__construct('Command rejected'); }
    public function render() { return $this->response; }
}

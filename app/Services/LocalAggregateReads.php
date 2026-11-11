<?php

namespace App\Services;

use App\Models\Asesor;
use App\Models\Egreso;
use App\Models\NotaVenta;
use App\Models\Pago;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class LocalAggregateReads
{
    public function sales(Request $request, array $filters, string $currency): Collection
    {
        $query = $this->notes($request, $filters, $currency)
            ->whereIn('estado', ['Activa', 'Completada', 'Anulada'])
            ->with(['cliente:id,nombre_completo', 'asesor:id,nombre_completo', 'propiedad:id,codigo,moneda']);
        $this->dates($query, 'fecha', $filters);

        return $query->orderByDesc('fecha')->orderByDesc('id')->get()->map(function (NotaVenta $sale) use ($currency) {
            $data = $sale->only(['id', 'fecha', 'estado', 'tipo_venta', 'monto_total', 'monto_comision', 'saldo_credito',
                'cliente_id', 'asesor_id', 'propiedad_id']);
            $data['tipo_venta'] = in_array($sale->tipo_venta, ['Contado', 'CONTADO'], true) ? 'CONTADO' : 'CREDITO';

            return $data + ['moneda' => $currency, 'cliente' => $sale->cliente?->toArray(),
                'asesor' => $sale->asesor?->toArray(), 'propiedad' => $sale->propiedad?->toArray()];
        });
    }

    public function payments(Request $request, array $filters, string $currency): Collection
    {
        $sales = $this->notes($request, $filters, $currency)->whereIn('estado', ['Activa', 'Completada']);
        if (isset($filters['ventas'])) {
            $sales->whereIn('id', explode(',', $filters['ventas']));
        }
        if (isset($filters['tipo_venta'])) {
            $sales->whereIn('tipo_venta', $filters['tipo_venta'] === 'CONTADO' ? ['CONTADO', 'Contado'] : ['CREDITO', 'Crédito']);
        }
        $query = Pago::where('estado', 'PAGADO')->whereIn('nota_venta_id', $sales->select('id'));
        $this->dates($query, 'fecha_pago', $filters);

        return $query->get(['id', 'nota_venta_id', 'fecha_pago', 'monto', 'concepto_pago'])->map(fn (Pago $payment) =>
            $payment->only(['id', 'nota_venta_id', 'monto', 'concepto_pago']) + ['fecha_pago' => $payment->fecha_pago->toDateString(), 'moneda' => $currency]);
    }

    public function accounts(Request $request, array $filters, string $currency): Collection
    {
        $accounts = $this->notes($request, $filters, $currency)
            ->whereIn('estado', ['Activa', 'Completada'])->whereIn('tipo_venta', ['CREDITO', 'Crédito'])
            ->with(['cliente:id,nombre_completo', 'asesor:id,nombre_completo', 'propiedad:id,codigo,moneda',
                'planPago.cuotas' => fn ($q) => $q->whereIn('estado', ['Pendiente', 'Vencida'])])->get();
        $payments = Pago::where('estado', 'PAGADO')->where('concepto_pago', 'CUOTA')
            ->whereIn('nota_venta_id', $accounts->modelKeys())->whereNotNull('cuota_id')
            ->selectRaw('cuota_id, SUM(monto) as total')->groupBy('cuota_id')->pluck('total', 'cuota_id');

        return $accounts->map(function (NotaVenta $account) use ($payments, $currency) {
            $quotas = collect($account->planPago?->cuotas)->map(fn ($quota) =>
                ['fecha_vencimiento' => $quota->fecha_vencimiento->toDateString()] + $quota->toArray()
                + InstallmentBalances::calculate($quota, (float) $payments->get($quota->id, 0)));

            return $account->only(['id', 'cliente_id', 'asesor_id', 'fecha']) + [
                'moneda' => $currency, 'cliente' => $account->cliente?->toArray(), 'asesor' => $account->asesor?->toArray(),
                'propiedad' => $account->propiedad?->toArray(), 'plan_pago' => ['cuotas' => $quotas->all()],
                'saldo_credito' => AggregateReports::sum($quotas, 'capital_pendiente'),
            ];
        });
    }

    public function expenses(array $period, array $filters): Collection
    {
        $query = Egreso::where('categoria', 'COMISION_ASESOR')
            ->with(['asesor:id,nombre_completo', 'notaVenta.cliente:id,nombre_completo', 'notaVenta.propiedad:id,codigo']);
        $this->dates($query, 'fecha', $period);
        if (isset($filters['asesor_id'])) {
            $query->where('asesor_id', $filters['asesor_id']);
        }

        return $query->orderByDesc('fecha')->orderByDesc('id')->get()->map(fn (Egreso $row) =>
            array_replace($row->toArray(), ['fecha' => $row->fecha->toDateString(), 'moneda' => AggregateReports::currency($row->moneda)]));
    }

    public function adviser(Request $request): array
    {
        $adviser = Asesor::where('user_id', $request->user()->id)->where('estado', true)->first();
        abort_unless($adviser, 403, 'No tienes un perfil de asesor asignado.');

        return $adviser->only(['id', 'nombre_completo']);
    }

    public function advisers(): Collection
    {
        return Asesor::where('estado', true)->orderBy('id')->get(['id', 'nombre_completo', 'porcentaje_comision'])
            ->toBase()->map(fn ($adviser) => $adviser->toArray());
    }

    private function notes(Request $request, array $filters, string $currency): Builder
    {
        $query = NotaVenta::whereHas('propiedad', fn ($property) => $property->where('moneda', $currency));
        if (isset($filters['propio'])) {
            $query->where('asesor_id', $this->adviser($request)['id']);
        } elseif (isset($filters['asesor_id'])) {
            $query->where('asesor_id', $filters['asesor_id']);
        }

        return $query;
    }

    private function dates(Builder $query, string $column, array $filters): void
    {
        if (isset($filters['desde'])) {
            $query->whereDate($column, '>=', $filters['desde']);
        }
        if (isset($filters['hasta'])) {
            $query->whereDate($column, '<=', $filters['hasta']);
        }
    }
}

<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class AggregateDashboard
{
    public function __construct(private AggregateReports $reports) {}

    public function data(Request $request, bool $own): array
    {
        $request->validate([
            'desde' => 'nullable|date_format:Y-m-d', 'hasta' => 'nullable|date_format:Y-m-d',
            'moneda' => 'nullable|in:BOB,USD',
        ]);
        $period = ['desde' => $request->input('desde') ?? now()->startOfMonth()->toDateString(),
            'hasta' => $request->input('hasta') ?? now()->toDateString()];
        abort_if($period['hasta'] < $period['desde'], 422);
        $history = ['desde' => min($period['desde'], CarbonImmutable::parse($period['hasta'])->startOfMonth()->subMonths(5)->toDateString()),
            'hasta' => $period['hasta']];
        $currency = AggregateReports::currency($request->input('moneda')) ?? 'BOB';
        $scope = $own ? ['propio' => 'true'] : [];
        $sales = $this->reports->sales($request, $history + $scope, $currency)->where('estado', 'Completada');
        $inPeriod = $sales->where('fecha', '>=', $period['desde']);
        $accounts = $this->reports->accounts($request, $scope, $currency);
        $installments = $this->reports->installments($accounts)->map(fn ($row) => $row + [
            'plan_pago' => ['nota_venta' => ['cliente' => ['nombre_completo' => $row['cliente']]]],
            'estado' => $row['dias_mora'] > 0 ? 'Vencida' : 'Pendiente',
        ]);
        $late = $installments->where('dias_mora', '>', 0);
        $kpis = ['ventas_monto' => AggregateReports::sum($inPeriod, 'monto_total'), 'ventas_cantidad' => $inPeriod->count()];
        $charts = ['ventas_por_mes' => $this->reports->months($sales, 'fecha', 'monto_total')];
        $balance = AggregateReports::sum($accounts, 'saldo_credito');
        $lateTotal = AggregateReports::sum($late, 'monto_cuota');

        if ($own) {
            $adviser = $this->reports->adviser($request);
            $upcoming = $installments->whereBetween('dias_mora', [-30, 0]);
            $kpis += ['comision_estimada' => AggregateReports::sum($inPeriod, 'monto_comision'),
                'cuotas_vencidas' => $late->count(), 'proximas_cuotas_7dias' => $upcoming->where('dias_mora', '>=', -7)->count()];
            $charts['mi_cartera'] = ['saldo_vigente' => $balance, 'monto_cuotas_vencidas' => $lateTotal];
            $lists = ['clientes_con_mora' => $late->take(15)->values(), 'proximas_cuotas' => $upcoming->take(15)->values()];
        } else {
            $payments = $this->reports->payments($request, $history, $currency);
            $commissions = $this->reports->expenses($request, $period)->where('estado', 'PAGADO')->where('moneda', $currency);
            $debtors = $accounts->where('saldo_credito', '>', 0);
            $kpis += ['cobros_monto' => AggregateReports::sum($payments->where('fecha_pago', '>=', $period['desde']), 'monto'),
                'mora_acumulada' => $lateTotal, 'cartera_activa' => $balance, 'clientes_credito' => $debtors->unique('cliente_id')->count(),
                'comisiones_pagadas' => AggregateReports::sum($commissions, 'monto')];
            $charts += [
                'cobros_por_mes' => $this->reports->months($payments, 'fecha_pago', 'monto'),
                'distribucion_tipo_venta' => $inPeriod->groupBy('tipo_venta')->map(fn ($rows, $type) =>
                    ['tipo_venta' => $type, 'cantidad' => $rows->count(), 'monto' => AggregateReports::sum($rows, 'monto_total')])->values(),
                'top_asesores' => $inPeriod->groupBy('asesor_id')->map(fn ($rows, $id) =>
                    ['asesor_id' => $id, 'asesor' => $rows->first()['asesor'], 'cantidad' => $rows->count(),
                        'monto' => AggregateReports::sum($rows, 'monto_total')])->sortByDesc('monto')->take(5)->values(),
            ];
            $lists = ['ultimas_ventas' => $sales->take(10)->values(), 'cuotas_vencidas' => $late->take(10)->values(),
                'top_deudores' => $debtors->sortByDesc('saldo_credito')->take(10)->values()];
        }

        return ['moneda' => $currency, 'kpis' => $kpis, 'graficos' => $charts, 'listados' => $lists]
            + ($own ? ['asesor' => $adviser] : []);
    }
}

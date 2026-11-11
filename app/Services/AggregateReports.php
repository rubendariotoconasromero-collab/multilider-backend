<?php

namespace App\Services;

use App\Architecture\ArchitectureMode;
use App\Services\Remote\AggregateReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class AggregateReports
{
    public function __construct(private AggregateReader $reader, private LocalAggregateReads $local, private ArchitectureMode $mode) {}

    public function data(Request $request, string $kind): array
    {
        $request->validate([
            'desde' => 'nullable|date_format:Y-m-d', 'hasta' => 'nullable|date_format:Y-m-d',
            'asesor_id' => 'nullable|integer|min:1', 'moneda' => 'nullable|in:BOB,USD,Bs,$,Todos',
            'ciudad_id' => 'nullable|integer|min:1', 'distrito_id' => 'nullable|integer|min:1',
            'sector_urbano_id' => 'nullable|integer|min:1', 'precio_min' => 'nullable|numeric|min:0',
            'precio_max' => 'nullable|numeric|min:0', 'page' => 'nullable|integer|min:1|max:1000000',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $desde = $request->filled('desde') ? $request->input('desde') : now()->startOfMonth()->toDateString();
        $hasta = $request->filled('hasta') ? $request->input('hasta') : now()->toDateString();
        abort_if($hasta < $desde, 422, 'La fecha final debe ser posterior o igual a la inicial.');
        $period = compact('desde', 'hasta');
        $currency = self::currency($request->input('moneda')) ?? 'BOB';
        $adviser = $request->filled('asesor_id') ? ['asesor_id' => $request->integer('asesor_id')] : [];
        $result = match ($kind) {
            'inventario-propiedades' => $this->inventory($request),
            'ventas-cobros' => $this->salesReport($request, $period, $adviser, $currency),
            'cartera-mora' => $this->arrears($request, $adviser, $currency),
            'comisiones' => $this->commissions($request, $period, $adviser),
            'desempeno-asesores' => $this->performance($request, $period, $adviser, $currency),
        };

        return $result + $period + ['moneda' => $currency];
    }

    public function sales(Request $request, array $filters, string $currency): Collection
    {
        $sales = $this->mode->isLocal() ? $this->local->sales($request, $filters, $currency)
            : $this->reader->all($request, 'comercial', 'api/lecturas/ventas', $filters)->where('moneda', $currency);

        return $sales->map(function (array $sale) {
                $sale['estado'] = $sale['estado'] === 'Activa' ? 'Completada' : $sale['estado'];
                $sale['tipo_venta'] = $sale['tipo_venta'] === 'CONTADO' ? 'Contado' : 'Crédito';

                return $sale;
            })->sortByDesc('fecha')->values();
    }

    public function payments(Request $request, array $filters, string $currency): Collection
    {
        if ($this->mode->isLocal()) {
            return $this->local->payments($request, $filters, $currency);
        }

        return $this->reader->all($request, 'finanzas', 'api/lecturas/cobros', $filters)->where('moneda', $currency)->values();
    }

    public function accounts(Request $request, array $filters, string $currency): Collection
    {
        if ($this->mode->isLocal()) {
            return $this->local->accounts($request, $filters, $currency);
        }

        return $this->reader->all($request, 'finanzas', 'api/lecturas/cartera', $filters, 10)
            ->where('moneda', $currency)->values();
    }

    public function expenses(Request $request, array $period, array $filters = []): Collection
    {
        if ($this->mode->isLocal()) {
            return $this->local->expenses($period, $filters);
        }

        return $this->reader->all($request, 'finanzas', 'api/compat/egresos', $filters + [
            'categoria' => 'COMISION_ASESOR', 'fecha_inicio' => $period['desde'], 'fecha_fin' => $period['hasta'],
        ]);
    }

    public static function sum(Collection $rows, string $key): float
    {
        return $rows->sum(fn ($row) => InstallmentBalances::cents($row[$key] ?? 0)) / 100;
    }

    public static function currency(?string $value): ?string
    {
        return match ($value) {
            'BOB', 'Bs' => 'BOB',
            'USD', '$' => 'USD',
            default => null,
        };
    }

    public function months(Collection $rows, string $date, string $amount): Collection
    {
        return $rows->groupBy(fn ($row) => substr($row[$date], 0, 7))->sortKeys()
            ->map(fn ($group, $month) => [
                'anio' => (int) substr($month, 0, 4), 'mes' => (int) substr($month, 5),
                'total' => self::sum($group, $amount), 'monto' => self::sum($group, $amount), 'cantidad' => $group->count(),
            ])->values();
    }

    public function installments(Collection $accounts): Collection
    {
        $today = CarbonImmutable::today();

        return $accounts->flatMap(fn ($account) => collect($account['plan_pago']['cuotas'] ?? [])
            ->whereIn('estado', ['Pendiente', 'Vencida'])->filter(fn ($quota) => $quota['monto_pendiente'] > 0)
            ->map(function ($quota) use ($account, $today) {
                $days = (int) CarbonImmutable::parse($quota['fecha_vencimiento'])->diffInDays($today, false);

                return [
                    'id' => $quota['id'], 'cliente' => $account['cliente']['nombre_completo'] ?? '-',
                    'cliente_id' => $account['cliente_id'], 'asesor' => $account['asesor']['nombre_completo'] ?? '-',
                    'asesor_id' => $account['asesor_id'], 'nota_venta_id' => $account['id'],
                    'propiedad' => $account['propiedad']['codigo'] ?? '-', 'numero_cuota' => $quota['numero_cuota'],
                    'fecha_vencimiento' => $quota['fecha_vencimiento'], 'dias_mora' => $days,
                    'tramo' => match (true) { $days <= 30 => '1-30 días', $days <= 60 => '31-60 días', $days <= 90 => '61-90 días', default => '+90 días' },
                    'monto_cuota' => $quota['monto_pendiente'], 'saldo_capital' => $quota['capital_pendiente'],
                ];
            }))->sortBy('fecha_vencimiento')->values();
    }

    private function advisers(Request $request): Collection
    {
        return $this->mode->isLocal() ? $this->local->advisers() : $this->reader->all($request, 'comercial', 'api/lecturas/asesores');
    }

    public function adviser(Request $request): array
    {
        return $this->mode->isLocal() ? $this->local->adviser($request) : $this->reader->read($request, 'comercial', 'api/lecturas/mi-asesor');
    }

    private function page(Request $request, Collection $rows): array
    {
        $size = $request->integer('per_page', 15);
        $page = $request->integer('page', 1);
        $data = $rows->forPage($page, $size)->values();
        $from = $data->isEmpty() ? null : ($page - 1) * $size + 1;

        return ['data' => $data, 'total' => $rows->count(), 'current_page' => $page, 'per_page' => $size,
            'last_page' => max(1, (int) ceil($rows->count() / $size)), 'from' => $from,
            'to' => $from === null ? null : $from + $data->count() - 1];
    }

    private function salePayments(Request $request, Collection $sales, string $currency): Collection
    {
        return $sales->pluck('id')->chunk(100)->flatMap(fn ($ids) =>
            $this->payments($request, ['ventas' => $ids->implode(',')], $currency))->groupBy('nota_venta_id');
    }

    private function salesReport(Request $request, array $period, array $adviser, string $currency): array
    {
        $type = $request->input('tipo_venta');
        $state = $request->input('estado');
        $sales = $this->sales($request, $period, $currency)
            ->when($adviser, fn ($rows) => $rows->where('asesor_id', $adviser['asesor_id']))
            ->when($type && $type !== 'Todos', fn ($rows) => $rows->where('tipo_venta', $type))
            ->when($state && $state !== 'Todos', fn ($rows) => $rows->where('estado', $state));
        $active = $sales->where('estado', 'Completada');
        $paymentType = match ($type) { 'Contado' => ['tipo_venta' => 'CONTADO'], 'Crédito' => ['tipo_venta' => 'CREDITO'], default => [] };
        $payments = $state === 'Anulada' ? collect() : $this->payments($request, $period + $adviser + $paymentType, $currency);
        $perSale = $this->salePayments($request, $active, $currency);
        $balances = $active->contains('tipo_venta', 'Crédito')
            ? $this->accounts($request, $adviser, $currency)->keyBy('id') : collect();
        $notes = $sales->map(function ($row) use ($perSale, $balances) {
            $row['cobrado'] = self::sum($perSale->get($row['id'], collect()), 'monto');
            $row['saldo_credito'] = $balances->get($row['id'])['saldo_credito'] ?? 0;

            return $row;
        });
        $kpis = [
            'total_ventas_monto' => self::sum($active, 'monto_total'), 'total_ventas_cantidad' => $active->count(),
            'ventas_contado_monto' => self::sum($active->where('tipo_venta', 'Contado'), 'monto_total'),
            'ventas_credito_monto' => self::sum($active->where('tipo_venta', 'Crédito'), 'monto_total'),
            'total_cobrado' => self::sum($payments, 'monto'),
        ];
        $grafico = ['ventas' => $this->months($active, 'fecha', 'monto_total'), 'cobros' => $this->months($payments, 'fecha_pago', 'monto')];
        $rows = $notes->map(fn ($sale) => array_replace($sale, [
            'cliente' => $sale['cliente']['nombre_completo'] ?? '-', 'asesor' => $sale['asesor']['nombre_completo'] ?? '-',
            'propiedad' => $sale['propiedad']['codigo'] ?? '-',
        ]))->values()->all();

        return ['rows' => $rows, 'body' => compact('kpis', 'grafico') + ['notas' => $this->page($request, $notes), 'asesores' => $this->advisers($request)]];
    }

    private function arrears(Request $request, array $adviser, string $currency): array
    {
        $accounts = $this->accounts($request, $adviser, $currency);
        $overdue = $this->installments($accounts)->where('dias_mora', '>', 0)
            ->when($request->filled('desde'), fn ($rows) => $rows->where('fecha_vencimiento', '>=', $request->input('desde')))
            ->when($request->filled('hasta'), fn ($rows) => $rows->where('fecha_vencimiento', '<=', $request->input('hasta')));
        $aging = collect([['1-30 días', 1, 30], ['31-60 días', 31, 60], ['61-90 días', 61, 90], ['+90 días', 91, null]])
            ->map(function ($band) use ($overdue) {
                [$label, $min, $max] = $band;
                $rows = $overdue->where('tramo', $label);

                return ['label' => $label, 'min' => $min, 'max' => $max, 'count' => $rows->count(),
                    'monto' => self::sum($rows, 'monto_cuota'), 'clientes' => $rows->unique('cliente_id')->count()];
            })->all();
        $kpis = ['cartera_total' => self::sum($accounts, 'saldo_credito'), 'monto_vencido' => self::sum($overdue, 'monto_cuota'),
            'clientes_en_mora' => $overdue->unique('cliente_id')->count(), 'cuotas_vencidas_count' => $overdue->count()];

        return ['rows' => $overdue->values()->all(), 'body' => compact('kpis', 'aging') + ['cuotas' => $this->page($request, $overdue), 'asesores' => $this->advisers($request)]];
    }

    private function commissions(Request $request, array $period, array $adviser): array
    {
        $currency = self::currency($request->input('moneda'));
        $state = $request->input('estado');
        $expenses = $this->expenses($request, $period, $adviser)
            ->when($currency, fn ($rows) => $rows->where('moneda', $currency))
            ->when($state && $state !== 'Todos', fn ($rows) => $rows->where('estado', $state));
        $pending = $expenses->where('estado', 'PENDIENTE');
        $paid = $expenses->where('estado', 'PAGADO');
        $void = $expenses->where('estado', 'ANULADO');
        $kpis = [
            'pendiente_bs' => self::sum($pending->where('moneda', 'BOB'), 'monto'), 'pendiente_usd' => self::sum($pending->where('moneda', 'USD'), 'monto'),
            'pagado_bs' => self::sum($paid->where('moneda', 'BOB'), 'monto'), 'pagado_usd' => self::sum($paid->where('moneda', 'USD'), 'monto'),
            'anulado_bs' => self::sum($void->where('moneda', 'BOB'), 'monto'), 'anulado_usd' => self::sum($void->where('moneda', 'USD'), 'monto'),
            'anulado_count' => $void->count(),
            'total_count' => $expenses->count(), 'pendiente_count' => $pending->count(), 'pagado_count' => $paid->count(),
        ];
        $advisers = $this->advisers($request)->keyBy('id');
        $expenses = $expenses->map(function ($row) use ($advisers) {
            $row['asesor'] ??= $advisers->get($row['asesor_id'] ?? 0);
            $row['moneda'] = $row['moneda'] === 'USD' ? '$' : 'Bs';

            return $row;
        });
        $grafico = $expenses->where('moneda', $currency === 'USD' ? '$' : 'Bs')->groupBy('asesor_id')->map(fn ($rows) => [
            'asesor' => $rows->first()['asesor']['nombre_completo'] ?? 'Sin asesor',
            'pendiente' => self::sum($rows->where('estado', 'PENDIENTE'), 'monto'),
            'pagado' => self::sum($rows->where('estado', 'PAGADO'), 'monto'),
        ])->sortByDesc('pendiente')->take(10)->values();
        $rows = $expenses->map(fn ($row) => array_replace($row, [
            'asesor' => $row['asesor']['nombre_completo'] ?? '-', 'propiedad' => $row['nota_venta']['propiedad']['codigo'] ?? '-',
            'cliente' => $row['nota_venta']['cliente']['nombre_completo'] ?? '-', 'nota_venta_id' => $row['nota_venta_id'] ?? null,
            'fecha_pago' => $row['estado'] === 'PAGADO' ? $row['fecha'] : null,
            'comprobante' => $row['comprobante'] ?? '', 'concepto' => $row['concepto'] ?? '',
        ]))->values()->all();

        return ['rows' => $rows, 'body' => compact('kpis', 'grafico') + $period + ['egresos' => $this->page($request, $expenses), 'asesores' => $advisers->values()]];
    }

    private function performance(Request $request, array $period, array $adviser, string $currency): array
    {
        $advisers = $this->advisers($request);
        $sales = $this->sales($request, $period, $currency)->where('estado', 'Completada')
            ->when($adviser, fn ($rows) => $rows->where('asesor_id', $adviser['asesor_id']));
        $paid = $this->salePayments($request, $sales, $currency);
        $sales = $sales->map(fn ($sale) => $sale + ['cobrado' => self::sum($paid->get($sale['id'], collect()), 'monto')]);
        $accounts = $this->accounts($request, $adviser, $currency);
        $overdue = $this->installments($accounts)->where('dias_mora', '>', 0)->groupBy('asesor_id');
        $byAdviser = $sales->groupBy('asesor_id');
        $accounts = $accounts->groupBy('asesor_id');
        $ranking = $advisers->concat($sales->pluck('asesor'))->unique('id')
            ->filter(fn ($row) => isset($row['id']))->when($adviser, fn ($rows) => $rows->where('id', $adviser['asesor_id']))->map(function ($row) use ($byAdviser, $accounts, $overdue) {
                $id = $row['id'];
                $sales = $byAdviser->get($id, collect());

                return ['id' => $id, 'nombre' => $row['nombre_completo'], 'telefono' => null, 'correo' => null,
                    'ventas_cantidad' => $sales->count(), 'monto_vendido' => self::sum($sales, 'monto_total'),
                    'cobrado' => self::sum($sales, 'cobrado'), 'comision_estimada' => self::sum($sales, 'monto_comision'),
                    'cartera_vigente' => self::sum($accounts->get($id, collect()), 'saldo_credito'),
                    'mora' => self::sum($overdue->get($id, collect()), 'monto_cuota')];
            })->sortByDesc('monto_vendido')->values();
        $top = $ranking->firstWhere('ventas_cantidad', '>', 0);
        $kpis = ['top_asesor' => $top ? ['nombre' => $top['nombre'], 'monto' => $top['monto_vendido']] : null,
            'total_vendido' => self::sum($ranking, 'monto_vendido'), 'total_cobrado' => self::sum($ranking, 'cobrado'),
            'total_comisiones' => self::sum($ranking, 'comision_estimada'), 'asesores_con_ventas' => $byAdviser->count(),
            'total_asesores' => $ranking->count()];

        return ['rows' => $ranking->all(), 'body' => compact('kpis', 'ranking') + [
            'ventas' => $this->page($request, $sales), 'grafico' => $this->months($sales, 'fecha', 'monto_total'), 'asesores' => $advisers,
        ]];
    }

    private function inventory(Request $request): array
    {
        $filters = array_filter($request->only(['tipo', 'estado', 'moneda', 'ciudad_id', 'distrito_id',
            'sector_urbano_id', 'precio_min', 'precio_max']), fn ($value) => $value !== null && $value !== '');
        $states = ['Disponible' => 0, 'Reservado' => 1, 'Vendido' => 2];
        $properties = $this->reader->all($request, 'propiedades', 'api/catalogo/inventario', $filters)
            ->sortBy(fn ($row) => [$states[$row['estado']] ?? 3, $row['tipo'], $row['codigo'], $row['id']])->values();
        $available = $properties->where('estado', 'Disponible');
        $kpis = ['total' => $properties->count(), 'disponibles' => $available->count(),
            'vendidas' => $properties->where('estado', 'Vendido')->count(), 'reservadas' => $properties->where('estado', 'Reservado')->count(),
            'valor_disponible_usd' => self::sum($available->where('moneda', 'USD'), 'precio_venta'),
            'valor_disponible_bob' => self::sum($available->where('moneda', 'BOB'), 'precio_venta'),
            'superficie_promedio' => round($properties->avg('superficie_m2') ?? 0, 2)];
        $graficoEstados = $properties->groupBy('estado')->map(fn ($rows, $key) => ['estado' => $key, 'total' => $rows->count()])->values();
        $graficoTipos = $properties->groupBy('tipo')->map(fn ($rows, $key) => ['tipo' => $key, 'total' => $rows->count(),
            'valor_bob' => self::sum($rows->where('moneda', 'BOB'), 'precio_venta'),
            'valor_usd' => self::sum($rows->where('moneda', 'USD'), 'precio_venta')])->sortByDesc('total')->values();

        return ['rows' => $properties->all(), 'body' => ['kpis' => $kpis, 'grafico_estados' => $graficoEstados,
            'grafico_tipos' => $graficoTipos, 'propiedades' => $this->page($request, $properties)]];
    }
}

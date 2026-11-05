<?php

namespace App\Services;

use App\Models\Cuota;
use App\Models\Pago;
use App\Models\PlanPago;
use Illuminate\Support\Collection;

final class InstallmentBalances
{
    public static function calculate(Cuota $quota, float $paid): array
    {
        $due = self::cents($quota->monto_cuota);
        $interest = self::cents($quota->monto_interes);
        $capital = self::cents($quota->monto_capital);
        $paidCents = max(0, self::cents($paid));
        $appliedInterest = min($interest, $paidCents);
        $appliedCapital = min($capital, max(0, $paidCents - $appliedInterest));
        // Legacy schedules retain their recorded cent rounding and paid status.
        $closed = $quota->estado === 'Pagada' || $paidCents >= $due;
        return [
            'monto_pagado' => $paidCents / 100,
            'interes_pagado' => $appliedInterest / 100,
            'capital_pagado' => $appliedCapital / 100,
            'monto_pendiente' => $closed ? 0 : max(0, $due - $paidCents) / 100,
            'interes_pendiente' => $closed ? 0 : max(0, $interest - $appliedInterest) / 100,
            'capital_pendiente' => $closed ? 0 : max(0, $capital - $appliedCapital) / 100,
        ];
    }

    public static function forPlan(PlanPago $plan, ?Collection $quotas = null): Collection
    {
        $quotas ??= $plan->cuotas()->get();
        $paid = Pago::where('nota_venta_id', $plan->nota_venta_id)->where('concepto_pago', 'CUOTA')
            ->where('estado', 'PAGADO')->whereIn('cuota_id', $quotas->pluck('id'))
            ->selectRaw('cuota_id, SUM(monto) as total')->groupBy('cuota_id')->pluck('total', 'cuota_id');
        return $quotas->mapWithKeys(fn (Cuota $quota) => [$quota->id => self::calculate($quota, (float) ($paid[$quota->id] ?? 0))]);
    }

    public static function outstandingCapital(PlanPago $plan, ?int $from = null): float
    {
        $quotas = $plan->cuotas()->whereIn('estado', ['Pendiente', 'Vencida'])
            ->when($from !== null, fn ($q) => $q->where('numero_cuota', '>=', $from))->get();
        return round(self::forPlan($plan, $quotas)->sum('capital_pendiente'), 2);
    }

    public static function cents(float|string|int|null $value): int { return (int) round((float) $value * 100); }
}

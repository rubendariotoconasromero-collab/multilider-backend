<?php

namespace App\Services;

final class FrenchInstallmentSchedule
{
    public static function amounts(float $capital, int $months, float $annualRate): array
    {
        $booked = InstallmentBalances::cents($capital);
        $rate = $annualRate / 1200;
        $factor = pow(1 + $rate, $months);
        if ($booked <= 0 || $months < 1 || $months > 600 || $rate < 0 || ! is_finite($factor) || $factor >= 1e28) {
            throw new \InvalidArgumentException('Las condiciones exceden la precisión admitida del plan.');
        }
        $fixed = $factor === 1.0 ? $capital / $months : $capital * $rate * $factor / ($factor - 1);
        $raw = $capital; $rows = [];
        for ($index = 0; $index < $months; $index++) {
            $interest = $raw * $rate;
            $principal = $index === $months - 1 ? $raw : $fixed - $interest;
            $raw -= $principal;
            $roundedInterest = max(0, InstallmentBalances::cents($interest));
            $roundedPrincipal = $index === $months - 1 ? $booked : min($booked, max(0, InstallmentBalances::cents($principal)));
            $booked -= $roundedPrincipal;
            $rows[] = ['monto_cuota' => ($roundedPrincipal + $roundedInterest) / 100, 'monto_interes' => $roundedInterest / 100,
                'monto_capital' => $roundedPrincipal / 100, 'saldo_capital' => $booked / 100];
        }
        return $rows;
    }
}

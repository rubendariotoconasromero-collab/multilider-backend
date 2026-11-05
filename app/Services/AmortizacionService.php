<?php

namespace App\Services;

use App\Models\Cuota;
use App\Models\Ingreso;
use App\Models\NotaVenta;
use App\Models\Pago;
use App\Models\PlanPago;
use Illuminate\Support\Facades\DB;

class AmortizacionService
{
    /**
     * Registra un pago extraordinario de capital y recalcula las cuotas restantes.
     *
     * Ejemplo: deuda de Bs. 6000 en 10 cuotas, cliente paga Bs. 3000 anticipado.
     * → se registra el pago, el saldo baja a Bs. 3000 y las cuotas pendientes
     *   se recalculan con ese nuevo capital (mismas fechas, mismos números).
     */
    public function amortizar(PlanPago $plan, array $datos, int $usuarioId): Pago
    {
        return DB::transaction(function () use ($plan, $datos, $usuarioId) {

            $sale = NotaVenta::with('propiedad')->lockForUpdate()->findOrFail($plan->nota_venta_id);
            abort_unless(in_array($sale->estado, ['Completada', 'Activa'], true), 409, 'La venta no está operativa.');
            $monto = (float) $datos['monto'];

            // 1. Calcular saldo capital actual
            $saldoActual = $this->saldoCapitalActual($plan);

            if ($monto <= 0 || $monto >= $saldoActual) {
                throw new \InvalidArgumentException(
                    "El monto debe ser mayor a 0 y menor al saldo capital ({$saldoActual})."
                );
            }

            // 2. Registrar el pago extraordinario
            $pago = Pago::create([
                'nota_venta_id'  => $plan->nota_venta_id,
                'cuota_id'       => null,
                'metodo_pago_id' => $datos['metodo_pago_id'],
                'cuenta_id'      => $datos['cuenta_id'],
                'concepto_pago'  => 'AMORTIZACION',
                'fecha_pago'     => $datos['fecha_pago'],
                'monto'          => $monto,
                'estado'         => 'PAGADO',
                'observaciones'  => $datos['observaciones'] ?? 'Amortización de capital',
            ]);

            // 3. Generar ingreso automático
            Ingreso::create([
                'fecha'              => $datos['fecha_pago'],
                'concepto'           => 'Amortización de capital - VTA #' . $plan->nota_venta_id,
                'categoria'          => 'CUOTA',
                'monto'              => $monto,
                'moneda'             => in_array($sale->propiedad?->moneda, ['USD', '$'], true) ? '$' : 'Bs',
                'origen'             => 'AUTOMATICO',
                'pago_id'            => $pago->id,
                'nota_venta_id'      => $plan->nota_venta_id,
                'cuenta_bancaria_id' => $datos['cuenta_id'],
                'user_id'            => $usuarioId,
                'estado'             => 'CONFIRMADO',
            ]);

            // 4. Recalcular cuotas pendientes con el nuevo saldo
            $nuevoSaldo = $saldoActual - $monto;
            $this->recalcularCuotasPendientes($plan, $nuevoSaldo);

            // 5. Actualizar saldo_credito en notas_ventas para reflejar el capital restante
            NotaVenta::where('id', $plan->nota_venta_id)
                ->update(['saldo_credito' => $nuevoSaldo]);

            return $pago;
        });
    }

    /**
     * Calcula el saldo de capital pendiente sumando monto_capital de todas las cuotas
     * en estado Pendiente o Vencida. Este valor es siempre correcto, incluso después
     * de recalcular cuotas por amortización o reprogramación.
     */
    public function saldoCapitalActual(PlanPago $plan): float
    {
        return InstallmentBalances::outstandingCapital($plan);
    }

    /**
     * Recalcula los montos de las cuotas pendientes con el nuevo capital.
     * Las fechas y números de cuota NO cambian — solo cambian los montos.
     */
    private function recalcularCuotasPendientes(PlanPago $plan, float $nuevoCapital): void
    {
        $cuotasPendientes = $plan->cuotas()
            ->whereIn('estado', ['Pendiente', 'Vencida'])
            ->orderBy('numero_cuota')
            ->get();

        if ($cuotasPendientes->isEmpty()) {
            return;
        }

        $balances = InstallmentBalances::forPlan($plan, $cuotasPendientes);
        $schedule = FrenchInstallmentSchedule::amounts($nuevoCapital, $cuotasPendientes->count(), (float) $plan->tasa_interes);
        foreach ($cuotasPendientes as $index => $cuota) {
            $next = $schedule[$index];
            // Same due date: carry the interest credit through subsequent amortizations.
            $credit = round((float) $cuota->interes_acreditado + $balances[$cuota->id]['interes_pagado'], 2);
            if ($credit > 0) {
                $next['interes_acreditado'] = $credit;
                $next['monto_interes'] = round(max(0, $next['monto_interes'] - $credit), 2);
                $next['monto_cuota'] = round($next['monto_capital'] + $next['monto_interes'], 2);
            }
            if ($balances[$cuota->id]['monto_pagado'] > 0) {
                $replacement = $cuota->replicate();
                $cuota->update(['estado' => 'Reprogramada']);
                $replacement->fill($next)->save();
            } else { $cuota->update($next); }
        }
    }
}

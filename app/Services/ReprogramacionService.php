<?php

namespace App\Services;

use App\Models\Cuota;
use App\Models\PlanPago;
use App\Models\Reprogramacion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReprogramacionService
{
    /**
     * Ejecuta una reprogramación sobre un plan de pagos.
     *
     * Cancela las cuotas pendientes desde cuota_desde en adelante y genera
     * nuevas cuotas con los parámetros indicados. Registra el evento en
     * la tabla reprogramaciones para auditoría.
     */
    public function reprogramar(PlanPago $plan, array $datos, int $usuarioId): Reprogramacion
    {
        return DB::transaction(function () use ($plan, $datos, $usuarioId) {

            abort_unless(in_array($plan->notaVenta->estado, ['Completada', 'Activa'], true), 409, 'La venta no está operativa.');
            $cuotaDesde      = (int) $datos['cuota_desde'];
            $nuevaTasa       = (float) $datos['nueva_tasa_interes'];
            $nuevasCuotas    = (int) $datos['nuevo_numero_cuotas'];
            $nuevaFechaInicio = Carbon::parse($datos['nueva_fecha_inicio']);

            $saldoCapital = InstallmentBalances::outstandingCapital($plan, $cuotaDesde);
            abort_unless($saldoCapital > 0, 422, 'El tramo seleccionado no tiene capital pendiente.');

            // 2. Marcar como Reprogramada todas las cuotas pendientes desde cuota_desde
            $plan->cuotas()
                ->where('numero_cuota', '>=', $cuotaDesde)
                ->whereIn('estado', ['Pendiente', 'Vencida'])
                ->update(['estado' => 'Reprogramada', 'updated_at' => now()]);

            // 3. Crear el registro de reprogramación
            $reprogramacion = Reprogramacion::create([
                'plan_pago_id'          => $plan->id,
                'usuario_id'            => $usuarioId,
                'motivo'                => $datos['motivo'],
                'fecha_reprogramacion'  => $datos['fecha_reprogramacion'] ?? now()->toDateString(),
                'cuota_desde'           => $cuotaDesde,
                'saldo_capital_momento' => $saldoCapital,
                'nueva_tasa_interes'    => $nuevaTasa,
                'nuevo_numero_cuotas'   => $nuevasCuotas,
                'nueva_fecha_inicio'    => $nuevaFechaInicio->toDateString(),
                'observaciones'         => $datos['observaciones'] ?? null,
            ]);

            // 4. Generar las nuevas cuotas con amortización francesa
            $this->generarNuevasCuotas($plan, $reprogramacion, $saldoCapital, $nuevaTasa, $nuevasCuotas, $nuevaFechaInicio, $cuotaDesde);

            // 5. Actualizar metadatos del plan de pagos
            $ultimaFecha = $nuevaFechaInicio->copy()->addMonths($nuevasCuotas - 1);
            $plan->update([
                'numero_cuotas' => $plan->cuotas()->whereNotIn('estado', ['Reprogramada', 'Anulada'])->count(),
                'tasa_interes'  => $nuevaTasa,
                'fecha_final'   => $ultimaFecha->toDateString(),
            ]);

            $plan->notaVenta->update(['saldo_credito' => InstallmentBalances::outstandingCapital($plan)]);
            return $reprogramacion->load('cuotas', 'usuario');
        });
    }

    private function generarNuevasCuotas(
        PlanPago $plan,
        Reprogramacion $reprogramacion,
        float $capital,
        float $tasaAnual,
        int $meses,
        Carbon $fechaInicio,
        int $numeracionDesde
    ): void {
        $fecha = $fechaInicio->copy();
        $insert = [];
        $used = $plan->cuotas()->whereNotIn('estado', ['Reprogramada', 'Anulada'])->pluck('numero_cuota')->flip();
        $number = $numeracionDesde;
        foreach (FrenchInstallmentSchedule::amounts($capital, $meses, $tasaAnual) as $amounts) {
            while ($used->has($number)) { $number++; }
            $insert[] = $amounts + ['plan_pago_id' => $plan->id, 'reprogramacion_id' => $reprogramacion->id,
                'numero_cuota' => $number++, 'fecha_vencimiento' => $fecha->format('Y-m-d'), 'estado' => 'Pendiente',
                'created_at' => now(), 'updated_at' => now()];
            $fecha->addMonth();
        }

        Cuota::insert($insert);
    }

    /**
     * Devuelve el historial de reprogramaciones de un plan con sus cuotas nuevas.
     */
    public function historial(PlanPago $plan): \Illuminate\Database\Eloquent\Collection
    {
        return $plan->reprogramaciones()
            ->with(['usuario:id,nombre', 'cuotas'])
            ->orderBy('created_at', 'desc')
            ->get();
    }
}

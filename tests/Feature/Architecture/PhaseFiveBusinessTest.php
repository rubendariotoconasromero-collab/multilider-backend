<?php

namespace Tests\Feature\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BusinessFixtures;
use Tests\TestCase;

final class PhaseFiveBusinessTest extends TestCase
{
    use BusinessFixtures, RefreshDatabase;

    public function test_local_sale_command_replay_commits_one_sale_and_receipt(): void
    {
        Sanctum::actingAs($this->user());
        $payload = $this->salePayload($this->property());
        $payload['operacion_id'] = (string) Str::uuid();
        $payload['command_id'] = (string) Str::uuid();
        $response = $this->postJson('/api/ventas', $payload);
        $this->assertSame(201, $response->status(), $response->getContent());
        $again = $this->postJson('/api/ventas', $payload);
        $this->assertSame(201, $again->status(), $again->getContent());
        $this->assertSame($response->json('data.id'), $again->json('data.id'));
        $this->assertDatabaseCount('notas_ventas', 1);
        $this->assertDatabaseCount('business_commands', 1);
        $payload['monto_total'] = 12000;
        $this->postJson('/api/ventas', $payload)->assertStatus(409);
    }

    public function test_partial_installments_pay_interest_first_and_amortization_retains_the_original_quota(): void
    {
        $user = $this->user(); $this->grant($user, 'acceso_pagos'); Sanctum::actingAs($user);
        $sale = $this->postJson('/api/ventas', array_replace($this->salePayload($this->property(), true), [
            'monto_total' => 1000, 'monto_liquido' => 1000, 'cuota_inicial' => 100, 'saldo_credito' => 900,
            'numero_cuotas' => 1, 'tasa_interes' => 12, 'descuento' => 0,
        ]))->assertCreated()->json('data.id');
        $quota = \App\Models\Cuota::firstOrFail();
        $input = ['nota_venta_id' => $sale, 'cuota_id' => $quota->id, 'concepto_pago' => 'CUOTA', 'fecha_pago' => '2026-11-04'];
        $first = $this->postJson('/api/pagos', $input + ['monto' => 5])->assertCreated()->json('data.id');
        $read = $this->getJson("/api/ventas/$sale/plan-pago")->assertOk();
        $this->assertEquals(900, $read->json('resumen.saldo_capital'));
        $this->assertEquals(4, $read->json('plan_pago.cuotas.0.interes_pendiente'));
        $this->postJson('/api/pagos', $input + ['monto' => 25])->assertCreated();
        $read = $this->getJson("/api/ventas/$sale/plan-pago")->assertOk();
        $this->assertEquals(879, $read->json('resumen.saldo_capital'));
        $this->assertEquals(21, $read->json('plan_pago.cuotas.0.capital_pagado'));
        $this->assertEquals(879, $read->json('plan_pago.cuotas.0.monto_pendiente'));
        $this->postJson('/api/pagos', $input + ['monto' => 900])->assertStatus(422);
        $company = DB::table('mi_empresa')->insertGetId(['nombre' => 'Empresa ficticia']);
        $bank = DB::table('cuentas_bancarias')->insertGetId(['mi_empresa_id' => $company, 'nombre' => 'Caja F5', 'tipo' => 'EFECTIVO']);
        $method = DB::table('metodos_pago')->insertGetId(['nombre_metodo' => 'Efectivo']);
        $amort = $this->postJson('/api/planes-pago/'.$quota->plan_pago_id.'/amortizar', ['monto' => 79, 'metodo_pago_id' => $method, 'cuenta_id' => $bank, 'fecha_pago' => '2026-11-05'])->assertCreated();
        $this->assertEquals(879, $amort->json('saldo_anterior')); $this->assertEquals(800, $amort->json('saldo_nuevo'));
        $this->assertDatabaseHas('cuotas', ['id' => $quota->id, 'estado' => 'Reprogramada', 'monto_capital' => 900]);
        $this->assertDatabaseHas('cuotas', ['estado' => 'Pendiente', 'monto_capital' => 800]);
        $this->putJson("/api/pagos/$first/cancelar")->assertStatus(409);
        $this->assertDatabaseCount('ingresos', 3);
    }

    public function test_new_plan_retains_the_last_cent_with_a_leap_year_schedule(): void
    {
        Sanctum::actingAs($this->user());
        $this->postJson('/api/ventas', array_replace($this->salePayload($this->property(), true), [
            'monto_total' => 1000.01, 'monto_liquido' => 1000.01, 'cuota_inicial' => 100, 'saldo_credito' => 900.01,
            'numero_cuotas' => 3, 'tasa_interes' => 0, 'fecha_inicio_pago' => '2028-01-31', 'descuento' => 0,
        ]))->assertCreated();
        $this->assertEquals(900.01, DB::table('cuotas')->sum('monto_capital'));
        $this->assertDatabaseHas('cuotas', ['numero_cuota' => 2, 'fecha_vencimiento' => '2028-03-02']);
        $this->assertDatabaseHas('cuotas', ['numero_cuota' => 3, 'monto_capital' => 300.01, 'saldo_capital' => 0]);
    }
}

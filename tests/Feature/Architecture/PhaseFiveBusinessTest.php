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

    public function test_local_bulk_failure_rolls_back_prior_payment_and_late_fee_and_replay_is_safe(): void
    {
        $user = $this->user();
        $this->grant($user, 'acceso_pagos');
        Sanctum::actingAs($user);
        $sale = $this->postJson('/api/ventas', array_replace($this->salePayload($this->property(), true), [
            'monto_total' => 1000, 'monto_liquido' => 1000, 'cuota_inicial' => 0, 'saldo_credito' => 1000,
            'numero_cuotas' => 2, 'tasa_interes' => 0, 'tasa_mora' => 3, 'descuento' => 0,
        ]))->assertCreated()->json('data.id');
        $ids = DB::table('cuotas')->orderBy('numero_cuota')->pluck('id');
        $company = DB::table('mi_empresa')->insertGetId(['nombre' => 'Empresa de pruebas']);
        $bank = DB::table('cuentas_bancarias')->insertGetId(['mi_empresa_id' => $company, 'nombre' => 'Caja de pruebas', 'tipo' => 'EFECTIVO']);
        $method = DB::table('metodos_pago')->insertGetId(['nombre_metodo' => 'Efectivo']);
        $batch = ['operacion_id' => (string) \Illuminate\Support\Str::uuid(), 'command_id' => (string) \Illuminate\Support\Str::uuid(),
            'nota_venta_id' => $sale, 'metodo_pago_id' => $method, 'cuenta_id' => $bank, 'fecha_pago' => '2027-02-05',
            'cuotas' => [['id' => $ids[0], 'monto' => 100, 'mora_monto' => 1], ['id' => $ids[1], 'monto' => 9999]]];

        $this->postJson('/api/pagos/bulk', $batch)->assertStatus(422);
        $this->assertDatabaseMissing('pagos', ['cuota_id' => $ids[0]]);
        $this->assertDatabaseCount('ingresos', 0);
        $this->assertDatabaseMissing('business_commands', ['command_id' => $batch['command_id']]);

        $batch['cuotas'][1]['monto'] = 50;
        $receipt = $this->postJson('/api/pagos/bulk', $batch)->assertCreated();
        $this->postJson('/api/pagos/bulk', $batch)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.0.id', $receipt->json('data.0.id'));
        $this->assertDatabaseHas('pagos', ['cuota_id' => $ids[0], 'concepto_pago' => 'MORA', 'monto' => 1, 'estado' => 'PAGADO']);
        $this->assertEquals(3, DB::table('pagos')->whereIn('cuota_id', $ids)->count());
        $this->assertEquals(151, DB::table('ingresos')->sum('monto'));
        $plan = $this->getJson("/api/ventas/$sale/plan-pago")->assertOk();
        $this->assertEquals(400, $plan->json('plan_pago.cuotas.0.monto_pendiente'));
        $this->assertEquals(450, $plan->json('plan_pago.cuotas.1.monto_pendiente'));
    }

    public function test_local_attachment_replacement_keeps_the_original_if_the_write_rolls_back(): void
    {
        $disk = \Illuminate\Support\Facades\Storage::fake('public');
        $user = $this->user();
        $this->grant($user, 'acceso_contratos');
        $this->grant($user, 'acceso_entregas');
        Sanctum::actingAs($user);
        $sale = $this->postJson('/api/ventas', $this->salePayload($this->property()))->assertCreated()->json('data.id');
        $contract = DB::table('contratos')->where('nota_venta_id', $sale)->first();
        $payload = ['firmado' => '1', 'fecha_firma' => '2026-11-04', 'fecha_programada_entrega' => '2026-11-06'];
        $pdf = static fn (string $label) => \Illuminate\Http\UploadedFile::fake()
            ->createWithContent($label.'.pdf', "%PDF-1.4\n% {$label}\n%%EOF");
        $headers = ['Accept' => 'application/json'];
        $contractRoute = "/api/contratos/{$contract->id}/gestionar";
        $this->post($contractRoute, $payload + ['archivo' => $pdf('original')], $headers)->assertOk();
        $delivery = DB::table('entregas')->where('contrato_id', $contract->id)->first();
        $deliveryRoute = "/api/entregas/{$delivery->id}/subir-acta";
        $this->post($deliveryRoute, ['acta' => $pdf('original')], $headers)->assertOk();

        $failingTable = null;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failingTable): void {
            if ($failingTable && str_starts_with(strtolower($sql), 'update '.chr(96).$failingTable.chr(96))) {
                throw new \RuntimeException('Fixture write interrupted before commit.');
            }
        });
        foreach ([
            ['contratos', $contract->id, 'url_doc', $contractRoute, 'archivo', $payload],
            ['entregas', $delivery->id, 'url_acta', $deliveryRoute, 'acta', []],
        ] as [$table, $id, $column, $route, $field, $fields]) {
            $oldUrl = DB::table($table)->where('id', $id)->value($column);
            $oldPath = ltrim(str_replace('/storage', '', $oldUrl), '/');
            $disk->assertExists($oldPath);
            try {
                $failingTable = $table;
                $this->post($route, $fields + [$field => $pdf('failed')], $headers)->assertServerError();
            } finally {
                $failingTable = null;
            }
            $this->assertDatabaseHas($table, ['id' => $id, $column => $oldUrl]);
            $disk->assertExists($oldPath);
            $this->post($route, $fields + [$field => $pdf('replacement')], $headers)->assertOk();
            $newUrl = DB::table($table)->where('id', $id)->value($column);
            $this->assertNotSame($oldUrl, $newUrl);
            $disk->assertExists(ltrim(str_replace('/storage', '', $newUrl), '/'));
            $disk->assertMissing($oldPath);
        }
    }
}

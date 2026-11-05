<?php

namespace Tests\Feature\Architecture;

use App\Models\Pago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BusinessFixtures;
use Tests\TestCase;

final class PhaseFivePaymentTest extends TestCase
{
    use BusinessFixtures, RefreshDatabase;

    public function test_verified_twenty_cent_test_never_settles_real_debt(): void
    {
        config(['services.libelula.api_key' => 'fixture-not-a-real-key', 'services.libelula.base_url' => 'https://payments.example.test']);
        Sanctum::actingAs($this->user());
        $sale = $this->salePayload($this->property());
        $this->postJson('/api/ventas', $sale)->assertCreated();
        $payment = Pago::firstOrFail();
        $operation = (string) Str::uuid(); $reference = 'TEMP-'.$operation;
        Http::fake([
            'payments.example.test/rest/deuda/registrar' => Http::response(['error' => 0, 'id_transaccion' => 'provider-fixture', 'url_pasarela_pagos' => 'https://payments.example.test/checkout']),
            'payments.example.test/rest/deuda/consultar_deudas/por_fechas' => Http::response(['error' => 0, 'datos' => [
                ['identificador' => $reference, 'pagado' => true, 'valor_total' => 0.20, 'moneda' => 'BOB'],
            ]]),
        ]);
        $payload = ['operacion_id' => $operation, 'cliente_id' => $sale['cliente_id'], 'pago_ids' => [$payment->id], 'cuota_ids' => [],
            'ci_pagador' => 'TEST', 'telefono_pagador' => '123', 'nombres_pagador' => 'Nombre', 'apellidos_pagador' => 'Fixture', 'correo_pagador' => 'fixture@example.test'];
        $this->postJson('/api/public/pagos/procesar', $payload)->assertOk()->assertJsonPath('es_prueba', true);
        $this->postJson('/api/public/pagos/procesar', $payload)->assertOk();
        Http::assertSentCount(1);
        $this->postJson('/api/public/pagos/confirmar-retorno', ['id_transaccion' => $reference])
            ->assertOk()->assertJsonPath('estado', 'PAGADO_PRUEBA')->assertJsonPath('deuda_real_liquidada', false);
        $this->assertDatabaseHas('pagos', ['id' => $payment->id, 'estado' => 'PENDIENTE_PAGO', 'monto' => 9500]);
        $this->assertDatabaseCount('ingresos', 0);
        $this->assertDatabaseCount('payment_test_orders', 1);
    }

    public function test_forged_callback_cannot_confirm_without_provider_proof(): void
    {
        config(['services.libelula.api_key' => 'fixture-not-a-real-key', 'services.libelula.base_url' => 'https://payments.example.test']);
        $operation = (string) Str::uuid(); $reference = 'TEMP-'.$operation;
        DB::table('payment_test_orders')->insert(['id' => $operation, 'reference' => $reference, 'request_hash' => str_repeat('a', 64),
            'cliente_id' => 1, 'state' => 'Registrada', 'amount' => 0.20, 'currency' => 'BOB', 'selection' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['*' => Http::response(['error' => 0, 'datos' => [['identificador' => $reference, 'pagado' => false, 'valor_total' => 0.20, 'moneda' => 'BOB']]])]);
        $payload = ['identificador' => $reference, 'transaction_id' => 'forged', 'error' => '0'];
        $this->postJson('/api/public/pagos/callback', $payload)->assertOk();
        $this->postJson('/api/public/pagos/callback', $payload)->assertOk();
        $this->assertDatabaseCount('payment_test_inbox', 1);
        $this->assertDatabaseHas('payment_test_orders', ['id' => $operation, 'state' => 'Registrada']);
        $this->assertDatabaseCount('ingresos', 0);
    }

    public function test_verified_reference_still_requires_exact_currency_and_amount(): void
    {
        config(['services.libelula.api_key' => 'fixture-not-a-real-key', 'services.libelula.base_url' => 'https://payments.example.test']);
        $reference = 'TEMP-'.Str::uuid();
        $row = ['identificador' => $reference, 'pagado' => true, 'valor_total' => '0.20', 'moneda' => 'BOB'];
        Http::fake(['*' => Http::sequence()
            ->push(['error' => 0, 'datos' => [array_replace($row, ['moneda' => 'USD'])]])
            ->push(['error' => 0, 'datos' => [array_replace($row, ['valor_total' => '0.204'])]])
            ->push(['error' => 0, 'datos' => [$row]])]);
        $gateway = app(\App\Services\LibelulaGateway::class);
        $this->assertFalse($gateway->verify($reference, 'BOB', '0.20', '2026-11-05 10:00:00'));
        $this->assertFalse($gateway->verify($reference, 'BOB', '0.20', '2026-11-05 10:00:00'));
        $this->assertTrue($gateway->verify($reference, 'BOB', '0.20', '2026-11-05 10:00:00'));
    }

    public function test_callback_write_failure_requires_retry_and_provider_timeout_keeps_the_order_pending(): void
    {
        config(['services.libelula.api_key' => 'fixture-not-a-real-key', 'services.libelula.base_url' => 'https://payments.example.test']);
        Http::preventStrayRequests();
        $operation = (string) Str::uuid();
        $reference = 'TEMP-'.$operation;
        DB::table('payment_test_orders')->insert(['id' => $operation, 'reference' => $reference,
            'request_hash' => str_repeat('b', 64), 'cliente_id' => 1, 'state' => 'Registrada',
            'amount' => 0.20, 'currency' => 'BOB', 'selection' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $verify = 0;
        Http::fake(['payments.example.test/rest/deuda/consultar_deudas/por_fechas' => function () use (&$verify, $reference) {
            if (++$verify === 1) {
                throw new \Illuminate\Http\Client\ConnectionException('Fixture read timeout');
            }
            return Http::response(['error' => 0, 'datos' => [
                ['identificador' => $reference, 'pagado' => true, 'valor_total' => '0.20', 'moneda' => 'BOB'],
            ]]);
        }]);
        $failWrite = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failWrite): void {
            if ($failWrite && str_starts_with(strtolower($sql), 'insert ignore into `payment_test_inbox`')) {
                throw new \RuntimeException('Fixture inbox write unavailable.');
            }
        });
        $callback = ['identificador' => $reference, 'transaction_id' => 'test-retry', 'error' => '0'];
        try {
            $this->postJson('/api/public/pagos/callback', $callback)->assertServerError();
            $this->assertDatabaseCount('payment_test_inbox', 0);
            $this->assertSame(0, $verify);
        } finally {
            $failWrite = false;
        }
        $this->postJson('/api/public/pagos/callback', $callback)->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('payment_test_inbox', ['reference' => $reference, 'state' => 'Pendiente']);
        $this->assertDatabaseHas('payment_test_orders', ['id' => $operation, 'state' => 'Registrada']);
        $this->postJson('/api/public/pagos/callback', $callback)->assertOk();
        $this->postJson('/api/public/pagos/callback', array_replace($callback, ['cancel_order' => '1']))->assertOk();
        $this->assertDatabaseCount('payment_test_inbox', 2);
        $this->assertDatabaseHas('payment_test_inbox', ['reference' => $reference, 'state' => 'Conciliada']);
        $this->assertDatabaseHas('payment_test_orders', ['id' => $operation, 'state' => 'PagadaPrueba']);
        $this->assertSame(2, $verify);
        $this->assertDatabaseCount('ingresos', 0);
        $this->assertDatabaseCount('pagos', 0);
    }
}

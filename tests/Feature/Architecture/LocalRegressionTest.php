<?php

namespace Tests\Feature\Architecture;

use App\Mail\InformeReporte;
use App\Models\Pago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BusinessFixtures;
use Tests\TestCase;

class LocalRegressionTest extends TestCase
{
    use BusinessFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.microservice_sync_enabled' => false,
            'services.seguridad.base_url' => '', 'services.propiedades.base_url' => '',
            'services.seguridad.sync_email' => null, 'services.seguridad.sync_password' => null]);
    }

    public function test_local_login_me_permissions_and_logout(): void
    {
        $user = $this->user();
        $this->grant($user, 'acceso_pagos');
        $this->assertTrue($user->hasPermission('acceso_pagos'));
        $this->assertFalse($user->hasPermission('acceso_empresa'));
        $token = $this->postJson('/api/login', ['correo' => $user->correo, 'password' => 'Test-only-Password-42!'])
            ->assertOk()->assertJsonPath('user.correo', $user->correo)->json('access_token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('id', $user->id);
        $this->withToken($token)->postJson('/api/empresa', [])->assertForbidden();
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_property_search_filters_pagination_and_detail(): void
    {
        $user = $this->user();
        $this->grant($user, 'acceso_propiedades');
        Sanctum::actingAs($user);
        $property = $this->property();
        $other = $this->property('OTHER-002');
        $sector = DB::table('sectores_urbanos')->find($property->sector_urbano_id);
        $city = DB::table('distritos')->find($sector->distrito_id)->ciudad_id;
        $this->getJson('/api/propiedades?'.http_build_query(['search' => 'TEST', 'ciudad_id' => $city,
            'distrito_id' => $sector->distrito_id, 'per_page' => 1]))->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $property->id)
            ->assertJsonStructure(['data', 'current_page', 'last_page', 'links', 'next_page_url']);
        $this->getJson('/api/propiedades?search=Propietario')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/propiedades?search=ausente')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/propiedades/'.$other->id)->assertOk()->assertJsonPath('codigo', 'OTHER-002');
    }

    public function test_cash_sale_and_cancellation_preserve_local_state(): void
    {
        Sanctum::actingAs($this->user());
        $property = $this->property();
        $response = $this->postJson('/api/ventas', $this->salePayload($property))->assertCreated();
        $id = $response->json('data.id');
        $this->assertDatabaseHas('propiedades', ['id' => $property->id, 'estado' => 'Vendido']);
        $this->assertDatabaseHas('contratos', ['nota_venta_id' => $id, 'estado' => 'Pendiente']);
        $this->assertDatabaseHas('pagos', ['nota_venta_id' => $id, 'estado' => 'PENDIENTE_PAGO', 'monto' => 9500]);
        $this->assertDatabaseCount('ingresos', 0);
        $this->assertDatabaseHas('egresos', ['nota_venta_id' => $id, 'monto' => 300, 'moneda' => 'Bs', 'estado' => 'PENDIENTE']);
        $this->putJson("/api/ventas/$id/anular")->assertOk();
        $this->assertDatabaseHas('propiedades', ['id' => $property->id, 'estado' => 'Disponible']);
        $this->assertDatabaseHas('egresos', ['nota_venta_id' => $id, 'estado' => 'ANULADO']);
    }

    public function test_credit_plan_and_commission_keep_existing_calculations(): void
    {
        Sanctum::actingAs($this->user());
        $property = $this->property();
        $id = $this->postJson('/api/ventas', $this->salePayload($property, true))->assertCreated()->json('data.id');
        $plan = DB::table('planes_pagos')->where('nota_venta_id', $id)->first();
        $this->assertSame(4, $plan->numero_cuotas);
        $this->assertDatabaseCount('cuotas', 4);
        $this->assertEquals(8000, DB::table('cuotas')->sum('monto_capital'));
        $this->assertEquals(0, DB::table('cuotas')->orderByDesc('numero_cuota')->first()->saldo_capital);
        $this->assertDatabaseHas('pagos', ['concepto_pago' => 'CUOTA_INICIAL', 'monto' => 2000, 'estado' => 'PENDIENTE_PAGO']);
        $this->assertDatabaseHas('egresos', ['monto' => 300, 'estado' => 'PENDIENTE']);
    }

    public function test_payment_confirmation_creates_income_only_once(): void
    {
        $user = $this->user();
        $this->grant($user, 'acceso_pagos');
        Sanctum::actingAs($user);
        $this->postJson('/api/ventas', $this->salePayload($this->property()))->assertCreated();
        $payment = Pago::firstOrFail();
        $company = DB::table('mi_empresa')->insertGetId(['nombre' => 'Empresa ficticia']);
        $account = DB::table('cuentas_bancarias')->insertGetId(['mi_empresa_id' => $company, 'nombre' => 'Caja de prueba', 'tipo' => 'EFECTIVO']);
        $method = DB::table('metodos_pago')->insertGetId(['nombre_metodo' => 'Efectivo']);
        $payload = ['metodo_pago_id' => $method, 'cuenta_id' => $account, 'fecha_pago' => '2026-11-04'];
        $this->postJson("/api/pagos/$payment->id/procesar", $payload)->assertOk();
        $this->postJson("/api/pagos/$payment->id/procesar", $payload)->assertStatus(400);
        $this->assertDatabaseCount('ingresos', 1);
        $this->assertDatabaseHas('ingresos', ['pago_id' => $payment->id, 'moneda' => 'Bs', 'monto' => 9500]);
    }

    public function test_inventory_pdf_excel_and_mail_recipient_use_local_users(): void
    {
        Mail::fake();
        $user = $this->user();
        Sanctum::actingAs($user);
        $this->property();
        $this->getJson('/api/reportes/destinatarios')->assertOk()->assertJsonPath('data.0.correo', $user->correo);
        $pdf = $this->get('/api/reportes/inventario-propiedades/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->get('/api/reportes/inventario-propiedades/excel')->assertOk();
        $this->postJson('/api/reportes/enviar-informe', ['reporte' => 'inventario-propiedades', 'usuario_ids' => [$user->id]])
            ->assertOk()->assertJsonPath('enviados', 1);
        Mail::assertSent(InformeReporte::class, fn ($mail) => $mail->hasTo($user->correo));
    }
}

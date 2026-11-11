<?php

namespace Tests\Feature\Architecture;

use App\Mail\InformeReporte;
use App\Jobs\SendCampaignBatch;
use App\Mail\CorreoMasivo;
use App\Models\Cliente;
use App\Models\NotaVenta;
use App\Models\Pago;
use App\Models\PlanPago;
use App\Services\MailCampaigns;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
        $this->getJson('/api/reportes/inventario-propiedades/pdf')->assertForbidden();
        $this->grant($user, 'acceso_propiedades');
        $this->property();
        $this->getJson('/api/reportes/destinatarios')->assertOk()->assertJsonPath('data.0.correo', $user->correo);
        $pdf = $this->get('/api/reportes/inventario-propiedades/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->get('/api/reportes/inventario-propiedades/excel')->assertOk();
        $this->postJson('/api/reportes/enviar-informe', ['reporte' => 'inventario-propiedades', 'usuario_ids' => [$user->id]])
            ->assertOk()->assertJsonPath('enviados', 1);
        Mail::assertSent(InformeReporte::class, fn ($mail) => $mail->hasTo($user->correo));
    }

    public function test_currency_report_uses_sale_and_payment_dates_and_excludes_reversals(): void
    {
        $user = $this->user();
        $this->grant($user, 'acceso_historial_ventas');
        $this->grant($user, 'acceso_pagos');
        Sanctum::actingAs($user);
        $bob = $this->property('F6-BOB');
        $usd = $this->property('F6-USD');
        $usd->update(['moneda' => 'USD']);
        $sale = $this->salePayload($bob);
        $prior = NotaVenta::create(array_replace($sale, ['fecha' => '2026-10-30', 'estado' => 'Completada']));
        NotaVenta::create(array_replace($sale, ['propiedad_id' => $usd->id, 'estado' => 'Completada']));
        foreach ([['PAGADO', 90], ['CANCELADO', 100]] as [$status, $amount]) {
            Pago::create(['nota_venta_id' => $prior->id, 'concepto_pago' => 'VENTA_CONTADO',
                'fecha_pago' => '2026-11-05', 'monto' => $amount, 'estado' => $status]);
        }
        $query = '/api/reportes/ventas-cobros?desde=2026-11-01&hasta=2026-11-30&moneda=';
        $this->getJson($query.'BOB')->assertOk()->assertJsonPath('kpis.total_ventas_cantidad', 0)->assertJsonPath('kpis.total_cobrado', 90);
        $this->getJson($query.'USD')->assertOk()->assertJsonPath('kpis.total_ventas_cantidad', 1)->assertJsonPath('kpis.total_cobrado', 0);
    }

    public function test_campaign_persists_recipients_and_worker_isolates_datasets_and_replays(): void
    {
        Queue::fake();
        Mail::fake();
        $user = $this->user();
        $this->grant($user, 'acceso_correo_masivo');
        Sanctum::actingAs($user);
        Cliente::create(['user_id' => $this->user('client@example.test')->id, 'ci' => 'F6-MAIL',
            'nombre_completo' => 'Cliente de campaña', 'correo' => 'client@example.test', 'estado' => true]);
        $preview = $this->postJson('/api/correo-masivo/preview-destinatarios', ['tipo' => 'activos'])
            ->assertOk()->assertJsonPath('total', 1)->json('preview_token');
        $payload = ['tipo' => 'activos', 'asunto' => 'Prueba', 'mensaje' => 'Prueba', 'preview_token' => $preview,
            'request_key' => (string) Str::uuid()];
        $id = $this->postJson('/api/correo-masivo/enviar', $payload)->assertCreated()->json('campana_id');
        $this->postJson('/api/correo-masivo/enviar', $payload)->assertOk()->assertJsonPath('campana_id', $id);
        Queue::assertPushed(SendCampaignBatch::class, 1);
        $job = Queue::pushed(SendCampaignBatch::class)->sole();
        config(['architecture.aggregate_dataset' => 'another-fixture']);
        $job->handle(app(MailCampaigns::class));
        Mail::assertNothingSent();
        $this->getJson("/api/correo-masivo/estado/$id")->assertNotFound();
        config(['architecture.aggregate_dataset' => 'default']);
        $job->handle(app(MailCampaigns::class));
        $job->handle(app(MailCampaigns::class));
        Mail::assertSent(CorreoMasivo::class, 1);
        $this->getJson("/api/correo-masivo/estado/$id")->assertOk()->assertJsonPath('total_enviados', 1);
        DB::table('campana_destinatarios')->where('campana_id', $id)
            ->update(['estado' => 'enviando', 'updated_at' => now()->subMinutes(11)]);
        $this->artisan('correo:reanudar', ['id' => $id])->assertSuccessful();
        $this->getJson("/api/correo-masivo/estado/$id")->assertOk()->assertJsonPath('total_inciertos', 1);
        Queue::assertPushed(SendCampaignBatch::class, 1);
    }

    public function test_local_arrears_uses_pending_interest_and_capital_and_excludes_replaced_installments(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00'));
        $user = $this->user();
        $this->grant($user, 'acceso_pagos');
        Sanctum::actingAs($user);
        $sale = NotaVenta::create($this->salePayload($this->property(), true));
        $plan = PlanPago::create(['nota_venta_id' => $sale->id, 'monto' => 100, 'numero_cuotas' => 1,
            'fecha_inicio' => '2026-11-06', 'fecha_final' => '2026-11-06', 'plazo' => '1 mes']);
        $quota = ['numero_cuota' => 1, 'monto_cuota' => 110, 'monto_interes' => 10,
            'monto_capital' => 100, 'saldo_capital' => 0, 'fecha_vencimiento' => '2026-11-06'];
        $plan->cuotas()->create($quota + ['estado' => 'Reemplazada']);
        $pending = $plan->cuotas()->create($quota + ['estado' => 'Pendiente']);
        Pago::create(['nota_venta_id' => $sale->id, 'cuota_id' => $pending->id, 'concepto_pago' => 'CUOTA',
            'fecha_pago' => '2026-11-07', 'monto' => 15, 'estado' => 'PAGADO']);

        $this->getJson('/api/reportes/cartera-mora?moneda=BOB&desde=2026-11-05&hasta=2026-11-11')
            ->assertOk()->assertJsonPath('kpis.cartera_total', 95)
            ->assertJsonPath('kpis.monto_vencido', 95)->assertJsonPath('kpis.cuotas_vencidas_count', 1)
            ->assertJsonPath('cuotas.data.0.id', $pending->id)->assertJsonPath('cuotas.data.0.dias_mora', 5);
    }
}

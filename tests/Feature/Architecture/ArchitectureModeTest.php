<?php

namespace Tests\Feature\Architecture;

use App\Architecture\ArchitectureMode;
use App\Contracts\Architecture\CatalogGateway;
use App\Contracts\Architecture\CommercialGateway;
use App\Contracts\Architecture\FinanceGateway;
use App\Contracts\Architecture\IdentityGateway;
use App\Jobs\EnviarCorreoMasivoJob;
use App\Services\Local\LocalCatalogGateway;
use App\Services\Local\LocalDomainGateway;
use App\Services\Remote\HttpClientFactory;
use App\Services\Remote\IntegrationException;
use App\Services\Remote\PendingDomainGateway;
use App\Services\Remote\RemoteCatalogGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BusinessFixtures;
use Tests\Support\FakeMicroserviceClients;
use Tests\Support\IsolatedDatabaseGuard;
use Tests\TestCase;

class ArchitectureModeTest extends TestCase
{
    use BusinessFixtures, RefreshDatabase;

    private function mode(string $value): void
    {
        config(['architecture.mode' => $value]);
        $this->app->forgetInstance(ArchitectureMode::class);
    }

    public function test_default_mode_and_domain_bindings(): void
    {
        $this->assertSame('local', (require config_path('architecture.php'))['mode']);
        $this->assertInstanceOf(LocalCatalogGateway::class, app(CatalogGateway::class));
        foreach ([IdentityGateway::class, CommercialGateway::class, FinanceGateway::class] as $contract) {
            $this->assertInstanceOf(LocalDomainGateway::class, app($contract));
        }
        $this->mode('microservices');
        $this->assertInstanceOf(RemoteCatalogGateway::class, app(CatalogGateway::class));
        foreach ([IdentityGateway::class, CommercialGateway::class, FinanceGateway::class] as $contract) {
            $this->assertInstanceOf(PendingDomainGateway::class, app($contract));
        }
    }

    public function test_invalid_mode_fails_explicitly(): void
    {
        $this->mode('typo');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_ARCHITECTURE');
        app(ArchitectureMode::class);
    }

    public function test_local_mode_overrides_sync_true_without_constructing_http_clients(): void
    {
        $fake = new FakeMicroserviceClients([]);
        $this->app->instance(HttpClientFactory::class, $fake);
        config(['services.microservice_sync_enabled' => true, 'services.seguridad.base_url' => null,
            'services.propiedades.base_url' => 'not-a-url', 'services.jwt_secret' => '']);
        Sanctum::actingAs($this->user());
        $property = $this->property();
        $id = $this->postJson('/api/ventas', $this->salePayload($property))->assertCreated()->json('data.id');
        $this->putJson("/api/ventas/$id/anular")->assertOk();
        $this->getJson('/api/propiedades')->assertOk();
        $this->assertSame([], $fake->services);
        $this->assertSame([], $fake->history);
        $this->assertFalse(Cache::has('propiedades_sync_jwt'));
    }

    /** @dataProvider modesAndFlags */
    public function test_legacy_commands_and_schedules_cannot_overwrite_business_data(string $mode, bool $flag): void
    {
        $this->mode($mode);
        config(['services.microservice_sync_enabled' => $flag]);
        $fake = new FakeMicroserviceClients([]);
        $this->app->instance(HttpClientFactory::class, $fake);
        $user = $this->user();
        $this->property();
        foreach (['usuarios:sync', 'propiedades:sync'] as $command) {
            $this->artisan($command)->expectsOutputToContain('INTEGRATION_NOT_READY')->assertExitCode(1);
        }
        Artisan::call('schedule:list');
        $this->assertStringNotContainsString('usuarios:sync', Artisan::output());
        $this->assertStringNotContainsString('propiedades:sync', Artisan::output());
        $this->assertDatabaseHas('users', ['id' => $user->id, 'correo' => $user->correo]);
        $this->assertDatabaseCount('propiedades', 1);
        $this->assertSame([], $fake->services);
    }

    public static function modesAndFlags(): array
    {
        return [['local', false], ['local', true], ['microservices', false], ['microservices', true]];
    }

    /** @dataProvider incompleteRoutes */
    public function test_remote_unready_routes_fail_before_local_reads_or_writes(string $method, string $uri): void
    {
        $this->mode('microservices');
        $this->property();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->json($method, $uri, ['correo' => 'nobody@example.test', 'password' => 'not-real'])
            ->assertStatus(503)->assertJsonPath('code', 'INTEGRATION_NOT_READY');
        $this->assertSame([], $queries, 'Readiness must run before model binding/auth/local mutations.');
    }

    public static function incompleteRoutes(): array
    {
        return [['POST', '/api/login'], ['POST', '/api/ventas'], ['PUT', '/api/ventas/1/anular'],
            ['POST', '/api/propiedades'], ['POST', '/api/pagos/1/procesar'], ['POST', '/api/public/pagos/callback'],
            ['GET', '/api/propiedades/1'], ['GET', '/api/landing'], ['POST', '/api/reportes/enviar-informe']];
    }

    public function test_repeated_sale_or_cancellation_closes_its_transaction(): void
    {
        Sanctum::actingAs($this->user());
        $payload = $this->salePayload($this->property());
        $id = $this->postJson('/api/ventas', $payload)->assertCreated()->json('data.id');
        $level = DB::transactionLevel();
        $this->postJson('/api/ventas', $payload)->assertStatus(400);
        $this->assertSame($level, DB::transactionLevel());
        $this->putJson("/api/ventas/$id/anular")->assertOk();
        $this->putJson("/api/ventas/$id/anular")->assertStatus(400);
        $this->assertSame($level, DB::transactionLevel());
    }

    public function test_test_database_guard_rejects_a_business_database_before_queries(): void
    {
        config(['database.connections.mysql.database' => 'business_database']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conexión de pruebas insegura');
        IsolatedDatabaseGuard::check($this->app);
    }

    public function test_stale_mail_job_does_not_send_or_write_after_mode_switch(): void
    {
        Mail::fake();
        $job = new EnviarCorreoMasivoJob(999, [['nombre' => 'Fixture', 'correo' => 'fixture@example.test']]);
        $this->mode('microservices');
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        try {
            $job->handle();
            $this->fail('Expected integration guard.');
        } catch (IntegrationException $e) {
            $this->assertSame('INTEGRATION_NOT_READY', $e->errorCode);
        }
        $job->failed(new \RuntimeException('fixture'));
        $this->assertSame([], $queries);
        Mail::assertNothingSent();
    }
}

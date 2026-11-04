<?php

namespace Tests\Feature\Architecture;

use App\Architecture\ArchitectureMode;
use App\Models\Propiedad;
use App\Services\Remote\HttpClientFactory;
use App\Services\Remote\LegacyPropertyStateBridge;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BusinessFixtures;
use Tests\Support\FakeMicroserviceClients;
use Tests\TestCase;

class LegacyBridgeTest extends TestCase
{
    use BusinessFixtures, RefreshDatabase;

    /** @dataProvider failures */
    public function test_enabled_transitional_bridge_catches_failure_without_retry_or_secrets(string $failure): void
    {
        config(['services.microservice_sync_enabled' => true]);
        $responses = match ($failure) {
            'timeout' => [new ConnectException('SECRET timeout', new Request('POST', 'https://fixture.example.test'))],
            '401' => [new Response(401, [], 'SECRET password')],
            '500' => [new Response(200, [], '{"access_token":"fixture"}'), new Response(500, [], 'SECRET database')],
        };
        $fake = new FakeMicroserviceClients($responses);
        Log::shouldReceive('warning')->once()->with('Puente antiguo de estado no completado.', \Mockery::on(fn ($ctx) => $ctx === ['service' => 'propiedades', 'operation' => 'state', 'property_id' => 42]));
        $property = new Propiedad;
        $property->id = 42;
        // Direct collaborator characterization only: remote sale HTTP routes are blocked by F2 readiness.
        (new LegacyPropertyStateBridge(new ArchitectureMode('microservices'), $fake))->push($property, 'Vendido');
        $this->assertCount(count($responses), $fake->history);
        foreach ($fake->history as $request) {
            $this->assertSame(2, $request['options']['connect_timeout']);
            $this->assertSame(5, $request['options']['timeout']);
        }
    }

    public static function failures(): array
    {
        return [['timeout'], ['401'], ['500']];
    }

    /** @dataProvider failures */
    public function test_transitional_bridge_failure_keeps_local_sale_atomic(string $failure): void
    {
        config(['services.microservice_sync_enabled' => true]);
        $responses = match ($failure) {
            'timeout' => [new ConnectException('fixture timeout', new Request('POST', 'https://fixture.example.test'))],
            '401' => [new Response(401)],
            '500' => [new Response(200, [], '{"access_token":"fixture"}'), new Response(500)],
        };
        // Characterize the legacy enabled collaborator without enabling a real remote route.
        // Normal local policy is tested separately and never constructs this client.
        $this->app->instance(LegacyPropertyStateBridge::class,
            new LegacyPropertyStateBridge(new ArchitectureMode('microservices'), new FakeMicroserviceClients($responses)));
        Sanctum::actingAs($this->user());
        $property = $this->property();
        $id = $this->postJson('/api/ventas', $this->salePayload($property))->assertCreated()->json('data.id');
        $this->assertDatabaseHas('propiedades', ['id' => $property->id, 'estado' => 'Vendido']);
        $this->assertDatabaseHas('pagos', ['nota_venta_id' => $id, 'estado' => 'PENDIENTE_PAGO']);
        $this->assertDatabaseHas('contratos', ['nota_venta_id' => $id, 'estado' => 'Pendiente']);
        $this->assertDatabaseCount('ingresos', 0);
    }

    public function test_false_flag_does_not_construct_client_in_either_mode(): void
    {
        config(['services.microservice_sync_enabled' => false]);
        $fake = new FakeMicroserviceClients([]);
        foreach (['local', 'microservices'] as $mode) {
            (new LegacyPropertyStateBridge(new ArchitectureMode($mode), $fake))->push(new Propiedad, 'Vendido');
        }
        $this->assertSame([], $fake->services);
    }

    public function test_factory_timeouts_tls_and_destinations(): void
    {
        $client = (new HttpClientFactory)->make('propiedades');
        $this->assertSame(2.0, $client->getConfig('connect_timeout'));
        $this->assertSame(5.0, $client->getConfig('timeout'));
        $this->assertTrue($client->getConfig('verify'));
        $this->assertFalse($client->getConfig('allow_redirects'));
        $this->expectException(\InvalidArgumentException::class);
        (new HttpClientFactory)->make('https://untrusted.example.test');
    }
}

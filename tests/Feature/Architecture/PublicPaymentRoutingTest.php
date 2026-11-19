<?php

namespace Tests\Feature\Architecture;

use App\Architecture\ArchitectureMode;
use App\Services\Remote\HttpClientFactory;
use GuzzleHttp\Psr7\Response;
use Tests\Support\FakeMicroserviceClients;
use Tests\TestCase;

final class PublicPaymentRoutingTest extends TestCase
{
    public static function callbackMethods(): array { return [['GET'], ['POST']]; }

    /** @dataProvider callbackMethods */
    public function test_callbacks_reach_finanzas_even_when_new_registrations_are_disabled(string $method): void
    {
        config(['architecture.mode' => 'microservices', 'architecture.business_enabled' => true, 'architecture.public_test_payments_enabled' => false]);
        app()->forgetInstance(ArchitectureMode::class);
        $clients = new FakeMicroserviceClients([new Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"es_prueba":true}')]);
        app()->instance(HttpClientFactory::class, $clients);
        $reference = 'TEMP-'.str_repeat('a', 8).'-aaaa-aaaa-aaaa-'.str_repeat('a', 12);
        $payload = ['identificador' => $reference, 'transaction_id' => 'provider-fixture'];
        $response = $method === 'GET' ? $this->getJson('/api/public/pagos/callback?'.http_build_query($payload))
            : $this->postJson('/api/public/pagos/callback', $payload);
        $response->assertOk()->assertJsonPath('es_prueba', true);
        $this->assertSame(['finanzas'], $clients->services);
        $this->assertCount(1, $clients->history);
        $request = $clients->history[0]['request'];
        $this->assertSame($method, $request->getMethod());
        $this->assertSame('/api/public/pagos/callback', $request->getUri()->getPath());
        if ($method === 'GET') parse_str($request->getUri()->getQuery(), $query);
        else $query = json_decode((string)$request->getBody(), true);
        $this->assertSame($reference, $query['identificador']);
        $this->assertSame('provider-fixture', $query['transaction_id']);
    }
}

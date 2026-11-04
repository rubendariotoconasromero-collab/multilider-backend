<?php

namespace Tests\Feature\Architecture;

use App\Architecture\ArchitectureMode;
use App\Contracts\Architecture\DelegatedTokenProvider;
use App\Services\Remote\HttpClientFactory;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BusinessFixtures;
use Tests\Support\FakeMicroserviceClients;
use Tests\TestCase;

class RemoteCatalogContractTest extends TestCase
{
    use BusinessFixtures, RefreshDatabase;

    private function remote(array $responses, bool $credential = true): FakeMicroserviceClients
    {
        config(['architecture.mode' => 'microservices']);
        $this->app->forgetInstance(ArchitectureMode::class);
        Sanctum::actingAs($this->user()); // Only a controlled test identity; actual remote login is F3.
        $fake = new FakeMicroserviceClients($responses);
        $this->app->instance(HttpClientFactory::class, $fake);
        if ($credential) {
            $this->app->instance(DelegatedTokenProvider::class, new class implements DelegatedTokenProvider
            {
                public function forRequest(Request $request, string $service): string
                {
                    return 'fixture.user.signature';
                }
            });
        }

        return $fake;
    }

    public function test_remote_filter_pagination_and_headers_match_public_contract(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/propiedades.remote.json')), true);
        $fake = $this->remote([new Response(200, [], json_encode($fixture))]);
        $correlation = '5d260fb9-3210-409c-980b-0114a177eaba';
        $command = 'f301dd2a-ecb3-468e-a48f-0049485d0cd9';
        $this->withHeaders(['X-Correlation-ID' => $correlation, 'Idempotency-Key' => $command])
            ->getJson('/api/propiedades?search=TEST&ciudad_id=2&distrito_id=3&per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.codigo', 'TEST-REMOTE')
            ->assertJsonPath('data.0.precio_venta', '10000.50')->assertJsonPath('data.0.moneda', 'BOB')
            ->assertJsonPath('current_page', 2)->assertJsonPath('last_page', 3)
            ->assertJsonPath('from', 2)->assertJsonPath('to', 2)
            ->assertJsonPath('next_page_url', 'http://localhost/api/propiedades?page=3')
            ->assertJsonStructure(['links', 'first_page_url', 'last_page_url', 'prev_page_url', 'path', 'total']);
        $sent = $fake->history[0];
        parse_str($sent['request']->getUri()->getQuery(), $query);
        $this->assertSame(['search' => 'TEST', 'ciudad_id' => '2', 'distrito_id' => '3', 'page' => '2', 'per_page' => '1'], $query);
        $this->assertSame('Bearer fixture.user.signature', $sent['request']->getHeaderLine('Authorization'));
        $this->assertSame($correlation, $sent['request']->getHeaderLine('X-Correlation-ID'));
        $this->assertSame($command, $sent['request']->getHeaderLine('Idempotency-Key'));
        $this->assertDatabaseCount('propiedades', 0);
    }

    public function test_empty_remote_page_keeps_laravel_empty_paginator(): void
    {
        $this->remote([new Response(200, [], json_encode(['data' => [], 'current_page' => 1, 'per_page' => 10, 'total' => 0, 'last_page' => 0]))]);
        $this->getJson('/api/propiedades')->assertOk()->assertJsonPath('data', [])->assertJsonPath('last_page', 1)
            ->assertJsonPath('from', null)->assertJsonPath('to', null)->assertJsonPath('next_page_url', null);
    }

    public function test_equivalent_origins_keep_public_listing_fields_and_pagination(): void
    {
        Sanctum::actingAs($this->user('local-reader@example.test'));
        $property = $this->property();
        $local = $this->getJson('/api/propiedades?search=TEST&per_page=1')->assertOk()->json();
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/propiedades.remote.json')), true);
        $fixture['data'][0] = array_replace($fixture['data'][0], [
            'id' => $property->id, 'codigo' => $property->codigo, 'precio_venta' => 10000,
            'sector_urbano_id' => $property->sector_urbano_id,
        ]);
        $fixture['total'] = 1;
        $fixture['current_page'] = 1;
        $fixture['last_page'] = 1;
        $this->remote([new Response(200, [], json_encode($fixture))]);
        $remote = $this->getJson('/api/propiedades?search=TEST&per_page=1')->assertOk()->json();
        foreach (['id', 'codigo', 'tipo', 'precio_venta', 'moneda', 'superficie_m2', 'estado', 'activo', 'es_destacado'] as $field) {
            $this->assertSame($local['data'][0][$field], $remote['data'][0][$field], $field);
        }
        foreach (['current_page', 'last_page', 'per_page', 'total', 'links', 'from', 'to', 'next_page_url', 'prev_page_url'] as $field) {
            $this->assertSame($local[$field], $remote[$field], $field);
        }
    }

    /** @dataProvider errorStatuses */
    public function test_problem_details_status_is_translated_without_local_fallback(int $status, int $expected): void
    {
        $property = $this->property();
        $fake = $this->remote([new Response($status, [], json_encode(['status' => $status,
            'title' => 'SECRET SQL connection password', 'detail' => 'SECRET TOKEN', 'errors' => ['page' => ['SECRET FIELD']]]))]);
        $response = $this->getJson('/api/propiedades')->assertStatus($expected);
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        if ($expected === 422) {
            $response->assertJsonValidationErrors('page');
        }
        $this->assertCount(1, $fake->history);
        $this->assertDatabaseHas('propiedades', ['id' => $property->id, 'estado' => 'Disponible']);
    }

    public static function errorStatuses(): array
    {
        return [[401, 401], [403, 403], [404, 404], [409, 409], [422, 422], [400, 422], [500, 503], [302, 503]];
    }

    public function test_timeout_is_bounded_and_does_not_read_local_catalog_or_retry(): void
    {
        $this->property();
        $fake = $this->remote([new ConnectException('SECRET connection details', new HttpRequest('GET', 'https://fixture.example.test'))]);
        $this->getJson('/api/propiedades')->assertStatus(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
        $this->assertCount(1, $fake->history);
        $this->assertSame(2, $fake->history[0]['options']['connect_timeout']);
        $this->assertSame(5, $fake->history[0]['options']['timeout']);
        $this->assertTrue($fake->history[0]['options']['verify']);
        $this->assertFalse($fake->history[0]['options']['allow_redirects']);
    }

    public function test_sanctum_token_is_never_forwarded_as_dotnet_credential(): void
    {
        $fake = $this->remote([], false);
        $this->withToken('123|not-a-dotnet-token')->getJson('/api/propiedades')->assertStatus(503)
            ->assertJsonPath('code', 'INTEGRATION_NOT_READY');
        $this->assertSame([], $fake->services);
    }

    /** @dataProvider malformedBodies */
    public function test_malformed_response_is_controlled(string $body): void
    {
        $this->remote([new Response(200, [], $body)]);
        $this->getJson('/api/propiedades')->assertStatus(503)->assertJsonPath('code', 'INVALID_SERVICE_RESPONSE');
    }

    public static function malformedBodies(): array
    {
        return [['not-json'], ['{}'], ['{"data":[],"total":0,"current_page":0,"per_page":0}'],
            ['{"data":["invalid"],"total":1,"current_page":1,"per_page":10}'],
            ['{"data":[{}],"total":1,"current_page":1,"per_page":10}']];
    }

    public function test_invalid_query_is_rejected_before_http(): void
    {
        $fake = $this->remote([]);
        $this->getJson('/api/propiedades?page=0&per_page=-1')->assertUnprocessable()->assertJsonValidationErrors(['page', 'per_page']);
        $this->assertSame([], $fake->services);
    }
}

<?php

namespace Tests\Feature\Architecture;

use App\Architecture\ArchitectureMode;
use App\Auth\RemotePrincipal;
use App\Contracts\Architecture\DelegatedTokenProvider;
use App\Models\ImagenPropiedad;
use App\Models\Propiedad;
use App\Services\MapsDns;
use App\Services\MapsUrlResolver;
use App\Services\Remote\HttpClientFactory;
use App\Services\Remote\RemoteAuthenticator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BusinessFixtures;
use Tests\Support\FakeMicroserviceClients;
use Tests\TestCase;

class CatalogPhaseFourTest extends TestCase
{
    use BusinessFixtures, RefreshDatabase;

    private function operator(): void
    {
        $user = $this->user();
        $this->grant($user, 'acceso_propiedades');
        Sanctum::actingAs($user);
    }

    private function payload(Propiedad $property): array
    {
        return ['propietario_ids' => $property->propietarios->pluck('id')->all(), 'sector_urbano_id' => $property->sector_urbano_id,
            'tipo' => 'Lote', 'moneda' => 'BOB', 'precio_venta' => '25000.25', 'superficie_m2' => '200.00', 'activo' => true, 'estado' => 'Disponible',
            'ubicacion' => ['referencia' => 'Fixture', 'latitud' => -17.3, 'longitud' => -63.2]];
    }

    public function test_local_aggregate_replay_and_stale_update_are_atomic(): void
    {
        $this->operator();
        $existing = $this->property();
        $body = $this->payload($existing);
        $key = (string) Str::uuid();
        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/propiedades', $body)->assertCreated();
        $id = $first->json('data.id');
        $version = $first->json('data.version');
        $this->postJson('/api/propiedades', $body)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('propiedades', 2);
        $this->assertDatabaseCount('ubicaciones', 1);
        $this->getJson('/api/catalogo/operaciones/'.$key)->assertOk()->assertJsonPath('result.data.id', $id);
        $this->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"'.$version.'"'])->putJson('/api/propiedades/'.$id, array_replace($body, ['precio_venta' => '30000.00']))->assertOk();
        $this->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"'.$version.'"'])->putJson('/api/propiedades/'.$id, $body)->assertStatus(412);
        $this->assertDatabaseHas('propiedades', ['id' => $id, 'precio_venta' => 30000]);
    }

    public function test_invalid_reference_does_not_leave_location_and_empty_features_clear(): void
    {
        $this->operator();
        $property = $this->property();
        $body = $this->payload($property);
        $body['propietario_ids'] = [99999];
        $this->postJson('/api/propiedades', $body)->assertUnprocessable();
        $this->assertDatabaseCount('ubicaciones', 0);
        $feature = DB::table('caracteristicas')->insertGetId(['nombre' => 'Fixture', 'estado' => true]);
        $property->caracteristicas()->attach($feature);
        $this->postJson('/api/propiedades/'.$property->id.'/caracteristicas/sync', ['caracteristica_ids' => []])->assertOk();
        $this->assertDatabaseCount('caracteristica_propiedad', 0);
    }

    public function test_selector_requires_property_permission_without_granting_owner_administration(): void
    {
        Sanctum::actingAs($this->user('denied@example.test'));
        $this->getJson('/api/propiedades')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->operator();
        $this->property();
        $data = $this->getJson('/api/catalogo/opciones-propiedad?kind=propietarios')->assertOk()->json('opciones.data.0');
        $this->assertArrayNotHasKey('ci', $data);
        $this->assertArrayNotHasKey('correo', $data);
        $this->getJson('/api/propietarios')->assertForbidden();
    }

    public function test_toggle_replay_runs_once_and_result_is_identity_scoped(): void
    {
        $this->operator();
        $property = $this->property();
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->deleteJson('/api/propiedades/'.$property->id)->assertOk();
        $this->deleteJson('/api/propiedades/'.$property->id)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseHas('propiedades', ['id' => $property->id, 'activo' => 0]);
        Sanctum::actingAs($this->user('other@example.test'));
        $this->getJson('/api/catalogo/operaciones/'.$key)->assertNotFound();
    }

    public function test_image_delete_commits_before_physical_cleanup_and_replaces_principal(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->operator();
        $property = $this->property();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2ioAAAAASUVORK5CYII=');
        $this->post('/api/propiedades/'.$property->id.'/imagenes', ['imagenes' => [UploadedFile::fake()->createWithContent('fixture.png', $png), UploadedFile::fake()->createWithContent('other.png', $png)]], ['Accept' => 'application/json'])->assertCreated();
        $first = ImagenPropiedad::orderBy('id')->firstOrFail();
        $path = $first->storage_key;
        $this->get($first->url)->assertOk();
        $property->update(['activo' => false]);
        $this->get($first->url)->assertNotFound();
        $this->getJson('/api/propiedades/'.$property->id)->assertOk()->assertJsonPath('imagenes.0.preview_url', '/api/catalogo/imagenes/'.$first->id.'/contenido');
        $this->get('/api/catalogo/imagenes/'.$first->id.'/contenido')->assertOk();
        $this->deleteJson('/api/imagenes-propiedades/'.$first->id)->assertOk();
        Storage::disk('local')->assertExists($path);
        $this->assertEquals(1, ImagenPropiedad::firstOrFail()->es_principal);
        $this->artisan('catalogo:limpiar-imagenes')->assertExitCode(0);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_remote_write_and_public_reader_do_not_touch_local_catalog(): void
    {
        $property = $this->property();
        config(['architecture.mode' => 'microservices']);
        $this->app->forgetInstance(ArchitectureMode::class);
        $this->app->instance(RemoteAuthenticator::class, new class extends RemoteAuthenticator
        {
            public function __construct() {}

            public function authenticate(Request $request): ?RemotePrincipal
            {
                return new RemotePrincipal(['id' => 99, 'roles_permisos' => [['rol_permiso' => ['permiso' => ['nombre' => 'acceso_ciudades']]]]], [], 'fixture.user.signature');
            }
        });
        $this->app->instance(DelegatedTokenProvider::class, new class implements DelegatedTokenProvider
        {
            public function forRequest(Request $request, string $service): string
            {
                return 'fixture.user.signature';
            }
        });
        $fake = new FakeMicroserviceClients([new Response(201, [], '{"id":777,"nombre":"Solo remoto","estado":true}'), new Response(200, [], '[]')]);
        $this->app->instance(HttpClientFactory::class, $fake);
        $this->postJson('/api/ciudades', ['nombre' => 'Solo remoto', 'departamento' => 'Fixture'])->assertCreated()->assertJsonPath('id', 777);
        $this->getJson('/api/landing/ciudades')->assertOk()->assertExactJson([]);
        $this->assertDatabaseCount('ciudades', 1);
        $this->assertSame('', $fake->history[1]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer fixture.user.signature', $fake->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertDatabaseHas('propiedades', ['id' => $property->id, 'codigo' => $property->codigo]);
    }

    public function test_remote_new_catalog_paths_authenticate_without_querying_business_models(): void
    {
        config(['architecture.mode' => 'microservices']);
        $this->app->forgetInstance(ArchitectureMode::class);
        $this->postJson('/api/propiedades', [])->assertUnauthorized();
        $this->getJson('/api/propiedades/1')->assertUnauthorized();
    }

    public function test_explicit_mapping_ledger_is_dry_by_default_and_never_overwrites_a_link(): void
    {
        $file = storage_path('framework/testing/catalog-map-fixture.json');
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        $manifest = ['database' => DB::connection()->getDatabaseName(), 'dataset' => 'fixture-f4', 'provenance' => 'synthetic review',
            'maps' => [['entity' => 'propiedad', 'source_id' => '1', 'target_id' => '77', 'target_service' => 'propiedades', 'review_status' => 'approved']]];
        file_put_contents($file, json_encode($manifest));
        $this->artisan('catalogo:mapas', ['file' => $file])->assertExitCode(0);
        $this->assertDatabaseCount('catalog_identity_maps', 0);
        $this->artisan('catalogo:mapas', ['file' => $file, '--apply' => true])->assertExitCode(0);
        $this->artisan('catalogo:mapas', ['file' => $file, '--apply' => true])->assertExitCode(0);
        $this->assertDatabaseCount('catalog_identity_maps', 1);
        $manifest['maps'][0]['target_id'] = '88';
        file_put_contents($file, json_encode($manifest));
        try {
            Artisan::call('catalogo:mapas', ['file' => $file, '--apply' => true]);
            $this->fail('Se sobrescribió un mapa.');
        } catch (\RuntimeException $expected) {
            $this->assertDatabaseHas('catalog_identity_maps', ['source_id' => '1', 'target_id' => '77']);
        }
    }

    public function test_local_reference_administration_preserves_crud_contracts(): void
    {
        $user = $this->user('reference-operator@example.test');
        foreach (['acceso_propiedades', 'acceso_ciudades', 'acceso_zonas', 'acceso_propietarios', 'acceso_caracteristicas'] as $permission) {
            $this->grant($user, $permission);
        }
        Sanctum::actingAs($user);
        $city = $this->postJson('/api/ciudades', ['nombre' => 'Ciudad á', 'departamento' => 'Prueba'])->assertCreated()->json('data.id');
        $district = $this->postJson('/api/distritos', ['nombre' => 'Distrito', 'ciudad_id' => $city])->assertCreated()->json('data.id');
        $sector = $this->postJson('/api/sectores-urbanos', ['nombre' => 'Sector', 'distrito_id' => $district, 'tipo' => 'Barrio'])->assertCreated()->json('data.id');
        $owner = $this->postJson('/api/propietarios', ['tipo' => 'empresa', 'nombre_completo' => 'Representante', 'nombre_empresa' => 'Empresa', 'ci' => null])->assertCreated()->json('data.id');
        $feature = $this->postJson('/api/caracteristicas', ['nombre' => 'Agua', 'tipo' => 'Servicio'])->assertCreated()->json('data.id');
        foreach ([
            ['ciudades', $city, ['nombre' => 'Ciudad actualizada', 'departamento' => 'Prueba']],
            ['distritos', $district, ['nombre' => 'Distrito actualizado', 'ciudad_id' => $city]],
            ['sectores-urbanos', $sector, ['nombre' => 'Sector actualizado', 'distrito_id' => $district, 'tipo' => 'Barrio']],
            ['propietarios', $owner, ['tipo' => 'empresa', 'nombre_completo' => 'Representante actualizado', 'nombre_empresa' => 'Empresa']],
            ['caracteristicas', $feature, ['nombre' => 'Agua actualizada', 'tipo' => 'Servicio']],
        ] as [$resource,$id,$payload]) {
            $this->getJson('/api/'.$resource.'/'.$id)->assertOk();
            $this->putJson('/api/'.$resource.'/'.$id, $payload)->assertOk();
            $this->deleteJson('/api/'.$resource.'/'.$id)->assertOk();
            $this->getJson('/api/'.$resource.'/'.$id)->assertOk()->assertJsonPath('estado', 0);
        }
        $this->postJson('/api/propietarios', ['tipo' => 'empresa', 'nombre_completo' => 'Incompleta'])->assertUnprocessable();
    }

    public function test_maps_checks_each_redirect_and_pins_dns(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(302, ['Location' => 'https://127.0.0.1/private']),
        ]));
        $handler->push(Middleware::history($history));
        $dns = new class extends MapsDns
        {
            public function addresses(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
        $resolver = new MapsUrlResolver(new Client(['handler' => $handler]), $dns);
        $response = $resolver->resolve(Request::create('/resolver-url-mapa', 'GET', ['url' => 'https://maps.app.goo.gl/fixture']));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(1, $history);
        $this->assertSame(['maps.app.goo.gl:443:8.8.8.8'], $history[0]['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $privateDns = new class extends MapsDns
        {
            public function addresses(string $host): array
            {
                return ['10.0.0.1'];
            }
        };
        $blocked = new MapsUrlResolver(new Client(['handler' => $handler]), $privateDns);
        $this->assertSame(422, $blocked->resolve(Request::create('/resolver-url-mapa', 'GET', ['url' => 'https://maps.app.goo.gl/fixture']))->getStatusCode());
        $this->assertCount(1, $history);
    }

    public function test_maps_keeps_latitude_longitude_contract(): void
    {
        $this->operator();
        foreach (['https://www.google.com/maps/@-17.3,-63.2,15z', 'https://www.google.com/maps/search/-17.3,+-63.2', 'https://maps.google.com/?q=-17.3,-63.2', 'https://www.google.com/maps?api=1&query=-17.3,-63.2'] as $url) {
            $this->getJson('/api/resolver-url-mapa?'.http_build_query(['url' => $url]))->assertOk()->assertJsonPath('lat', -17.3)->assertJsonPath('lng', -63.2);
        }
    }

    public function test_map_resolver_rejects_local_and_unapproved_destinations_without_fetch(): void
    {
        foreach (['http://127.0.0.1/', 'https://127.0.0.1/', 'https://example.com/', 'https://www.google.com@127.0.0.1/'] as $url) {
            try {
                app(MapsUrlResolver::class)->destination($url);
                $this->fail('Destino inválido aceptado');
            } catch (\InvalidArgumentException $expected) {
                $this->assertTrue(true);
            }
        }
    }
}

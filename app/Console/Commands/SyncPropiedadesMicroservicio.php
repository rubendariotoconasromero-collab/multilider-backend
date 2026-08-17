<?php

namespace App\Console\Commands;

use App\Models\Caracteristica;
use App\Models\Ciudad;
use App\Models\Distrito;
use App\Models\ImagenPropiedad;
use App\Models\Propiedad;
use App\Models\Propietario;
use App\Models\SectorUrbano;
use App\Models\Ubicacion;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncPropiedadesMicroservicio extends Command
{
    protected $signature = 'propiedades:sync';

    protected $description = 'Espeja el catálogo de MSMultilider.Propiedades (ciudades, distritos, sectores, propietarios, características, propiedades) en la copia local de Laravel.';

    private Client $http;

    public function handle(): int
    {
        $this->http = new Client([
            'base_uri' => rtrim(config('services.propiedades.base_url'), '/') . '/',
            'timeout' => 15,
        ]);

        try {
            $this->syncCiudades();
            $this->syncDistritos();
            $this->syncSectoresUrbanos();
            $this->syncPropietarios();
            $this->syncCaracteristicas();
            $this->syncPropiedades();
        } catch (\Throwable $e) {
            $this->error('Sync fallido: ' . $e->getMessage());
            Log::error('propiedades:sync falló', ['exception' => $e]);
            return self::FAILURE;
        }

        $this->info('Sincronización de propiedades completada.');
        return self::SUCCESS;
    }

    // ── Auth contra MSMultilider.Seguridad ──────────────────────────────────

    private function token(): string
    {
        return Cache::remember('propiedades_sync_jwt', now()->addHours(7), function () {
            $client = new Client(['base_uri' => rtrim(config('services.seguridad.base_url'), '/') . '/']);

            $res = $client->post('api/auth/login', [
                'json' => [
                    'correo' => config('services.seguridad.sync_email'),
                    'password' => config('services.seguridad.sync_password'),
                ],
            ]);

            $body = json_decode($res->getBody()->getContents(), true);

            return $body['access_token'] ?? $body['accessToken'];
        });
    }

    /**
     * Trae todas las páginas de un endpoint paginado del microservicio.
     */
    private function fetchAllPages(string $path): array
    {
        $items = [];
        $page = 1;
        $lastPage = 1;

        do {
            $res = $this->http->get($path, [
                'headers' => ['Authorization' => 'Bearer ' . $this->token()],
                'query' => ['page' => $page, 'per_page' => 200],
            ]);

            $body = json_decode($res->getBody()->getContents(), true);
            $items = array_merge($items, $body['data'] ?? []);
            $lastPage = $body['last_page'] ?? 1;
            $page++;
        } while ($page <= $lastPage);

        return $items;
    }

    /**
     * Crea o actualiza un modelo forzando que su PK coincida con el ID del microservicio.
     */
    private function upsertById(string $modelClass, int $id, array $attributes): Model
    {
        /** @var Model $model */
        $model = $modelClass::find($id) ?? new $modelClass();
        $model->id = $id;
        $model->fill($attributes);
        $model->save();

        return $model;
    }

    // ── Sync por entidad (orden de dependencias) ────────────────────────────

    private function syncCiudades(): void
    {
        foreach ($this->fetchAllPages('api/ciudades') as $dto) {
            $this->upsertById(Ciudad::class, $dto['id'], [
                'nombre' => $dto['nombre'],
                'departamento' => $dto['departamento'] ?? null,
                'estado' => $dto['estado'],
            ]);
        }
        $this->line('Ciudades sincronizadas.');
    }

    private function syncDistritos(): void
    {
        foreach ($this->fetchAllPages('api/distritos') as $dto) {
            $this->upsertById(Distrito::class, $dto['id'], [
                'ciudad_id' => $dto['ciudad_id'],
                'nombre' => $dto['nombre'],
                'estado' => $dto['estado'],
            ]);
        }
        $this->line('Distritos sincronizados.');
    }

    private function syncSectoresUrbanos(): void
    {
        foreach ($this->fetchAllPages('api/sectores-urbanos') as $dto) {
            $this->upsertById(SectorUrbano::class, $dto['id'], [
                'distrito_id' => $dto['distrito_id'],
                'nombre' => $dto['nombre'],
                'tipo' => $dto['tipo'] ?? null,
                'uv' => $dto['uv'] ?? null,
                'manzano' => $dto['manzano'] ?? null,
                'estado' => $dto['estado'],
            ]);
        }
        $this->line('Sectores urbanos sincronizados.');
    }

    private function syncPropietarios(): void
    {
        foreach ($this->fetchAllPages('api/propietarios') as $dto) {
            $this->upsertById(Propietario::class, $dto['id'], [
                'tipo' => $dto['tipo'],
                'ci' => $dto['ci'] ?? null,
                'lugar_expedicion' => $dto['lugar_expedicion'] ?? null,
                'nombre_completo' => $dto['nombre_completo'],
                'nombre_empresa' => $dto['nombre_empresa'] ?? null,
                'nit' => $dto['nit'] ?? null,
                'telefono' => $dto['telefono'] ?? null,
                'correo' => $dto['correo'] ?? null,
                'direccion' => $dto['direccion'] ?? null,
                'estado' => $dto['estado'],
            ]);
        }
        $this->line('Propietarios sincronizados.');
    }

    private function syncCaracteristicas(): void
    {
        foreach ($this->fetchAllPages('api/caracteristicas') as $dto) {
            $this->upsertById(Caracteristica::class, $dto['id'], [
                'nombre' => $dto['nombre'],
                'tipo' => $dto['tipo'] ?? null,
                'estado' => $dto['estado'],
            ]);
        }
        $this->line('Características sincronizadas.');
    }

    private function syncPropiedades(): void
    {
        foreach ($this->fetchAllPages('api/propiedades') as $dto) {
            if (!empty($dto['ubicacion'])) {
                $this->upsertById(Ubicacion::class, $dto['ubicacion']['id'], [
                    'referencia' => $dto['ubicacion']['referencia'] ?? null,
                    'url_maps' => $dto['ubicacion']['url_maps'] ?? null,
                    'longitud' => $dto['ubicacion']['longitud'] ?? null,
                    'latitud' => $dto['ubicacion']['latitud'] ?? null,
                ]);
            }

            /** @var Propiedad $propiedad */
            $propiedad = $this->upsertById(Propiedad::class, $dto['id'], [
                'sector_urbano_id' => $dto['sector_urbano_id'],
                'ubicacion_id' => $dto['ubicacion_id'] ?? null,
                'codigo' => $dto['codigo'] ?? null,
                'tipo' => $dto['tipo'],
                'precio_venta' => $dto['precio_venta'],
                'moneda' => $dto['moneda'],
                'superficie_m2' => $dto['superficie_m2'],
                'superficie_construida_m2' => $dto['superficie_construida_m2'] ?? null,
                'frente_mts' => $dto['frente_mts'] ?? null,
                'fondo_mts' => $dto['fondo_mts'] ?? null,
                'habitaciones' => $dto['habitaciones'] ?? null,
                'banos' => $dto['banos'] ?? null,
                'es_esquina' => $dto['es_esquina'],
                'direccion' => $dto['direccion'] ?? null,
                'nro_lote' => $dto['nro_lote'] ?? null,
                'colinda_norte' => $dto['colinda_norte'] ?? null,
                'colinda_sur' => $dto['colinda_sur'] ?? null,
                'colinda_este' => $dto['colinda_este'] ?? null,
                'colinda_oeste' => $dto['colinda_oeste'] ?? null,
                'estado' => $dto['estado'],
                'activo' => $dto['activo'],
                'es_destacado' => $dto['es_destacado'],
            ]);

            $propiedad->propietarios()->sync(array_column($dto['propietarios'] ?? [], 'id'));
            $propiedad->caracteristicas()->sync(array_column($dto['caracteristicas'] ?? [], 'id'));

            foreach ($dto['imagenes'] ?? [] as $img) {
                $this->upsertById(ImagenPropiedad::class, $img['id'], [
                    'propiedad_id' => $propiedad->id,
                    'url' => $img['url'],
                    'es_principal' => $img['es_principal'],
                ]);
            }
        }
        $this->line('Propiedades sincronizadas.');
    }
}

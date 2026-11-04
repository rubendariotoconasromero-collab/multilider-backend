<?php

namespace App\Services\Remote;

use App\Architecture\ArchitectureMode;
use App\Models\Propiedad;
use Illuminate\Support\Facades\Log;

/** Transitional bridge only. Not a distributed sale workflow or a data synchronizer. */
final class LegacyPropertyStateBridge
{
    public function __construct(private ArchitectureMode $mode, private HttpClientFactory $clients) {}

    public function push(Propiedad $property, string $state): void
    {
        if (! config('services.microservice_sync_enabled') || $this->mode->isLocal()) {
            return; // Must precede client construction or credential/token access.
        }
        try {
            $login = $this->clients->make('seguridad')->request('POST', 'api/auth/login', ['json' => [
                'correo' => config('services.seguridad.sync_email'), 'password' => config('services.seguridad.sync_password'),
            ]]);
            if ($login->getStatusCode() !== 200) {
                throw new \RuntimeException('Login rejected');
            }
            $body = json_decode((string) $login->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $token = $body['access_token'] ?? $body['accessToken'] ?? null;
            if (! is_string($token) || $token === '') {
                throw new \RuntimeException('Missing token');
            }
            $client = $this->clients->make('propiedades');
            $options = ['headers' => ['Authorization' => 'Bearer '.$token]];
            $response = $client->request('GET', 'api/propiedades/'.$property->id, $options);
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Read rejected');
            }
            $dto = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($dto)) {
                throw new \RuntimeException('Invalid property');
            }
            $fields = ['sector_urbano_id', 'ubicacion_id', 'tipo', 'codigo', 'precio_venta', 'moneda',
                'superficie_m2', 'superficie_construida_m2', 'frente_mts', 'fondo_mts', 'habitaciones', 'banos',
                'es_esquina', 'direccion', 'nro_lote', 'colinda_norte', 'colinda_sur', 'colinda_este', 'colinda_oeste', 'activo'];
            $payload = array_intersect_key($dto, array_flip($fields));
            $payload['estado'] = $state;
            $payload['propietario_ids'] = array_column($dto['propietarios'] ?? [], 'id');
            $payload['caracteristicas'] = array_column($dto['caracteristicas'] ?? [], 'id');
            $response = $client->request('PUT', 'api/propiedades/'.$property->id, $options + ['json' => $payload]);
            if ($response->getStatusCode() >= 300) {
                throw new \RuntimeException('Update rejected');
            }
        } catch (\Throwable $e) {
            Log::warning('Puente antiguo de estado no completado.', ['service' => 'propiedades', 'operation' => 'state', 'property_id' => $property->id]);
        }
    }
}

<?php

namespace App\Services\Remote;

use App\Contracts\Architecture\DelegatedTokenProvider;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class CatalogClient
{
    public function __construct(private HttpClientFactory $clients, private DelegatedTokenProvider $tokens) {}

    public function request(Request $request, string $method, string $path, bool $public = false, bool $bytes = false): array
    {
        if (! preg_match('~^api/(propiedades|propietarios|ubicaciones|ciudades|distritos|sectores-urbanos|caracteristicas|catalogo|public/catalogo)(/|$)~', $path)) {
            throw new \InvalidArgumentException('Ruta desconocida.');
        }
        $headers = ['X-Correlation-ID' => Str::isUuid($request->header('X-Correlation-ID', '')) ? $request->header('X-Correlation-ID') : (string) Str::uuid()];
        if (! $public) {
            $headers['Authorization'] = 'Bearer '.$this->tokens->forRequest($request, 'propiedades');
        }
        foreach (['Idempotency-Key', 'If-Match'] as $name) {
            if ($request->hasHeader($name)) {
                $headers[$name] = $request->header($name);
            }
        }
        $options = ['headers' => $headers];
        if ($method === 'GET') {
            $options['query'] = $request->only(['search', 'tipo', 'ciudad_id', 'distrito_id', 'sector_urbano_id', 'precio_min', 'precio_max', 'habitaciones', 'page', 'per_page', 'kind']);
        } elseif ($request->hasFile('imagenes')) {
            $options['timeout'] = 15;
            $options['multipart'] = [];
            foreach ($request->file('imagenes', []) as $file) {
                if ($file->getSize() > 2048 * 1024) {
                    throw new IntegrationException('Cada imagen admite 2048 KiB.', 422, 'VALIDATION_ERROR', ['imagenes' => ['Imagen demasiado grande.']]);
                }
                $options['multipart'][] = ['name' => 'imagenes[]', 'contents' => fopen($file->getRealPath(), 'rb'), 'filename' => $file->getClientOriginalName()];
            }
        } else {
            $options['json'] = $request->only(['nombre', 'departamento', 'estado', 'ciudad_id', 'distrito_id', 'tipo', 'uv', 'manzano',
                'ci', 'lugar_expedicion', 'nombre_completo', 'nombre_empresa', 'nit', 'telefono', 'correo', 'direccion',
                'propietario_ids', 'sector_urbano_id', 'ubicacion_id', 'ubicacion', 'codigo', 'precio_venta', 'moneda', 'superficie_m2',
                'superficie_construida_m2', 'frente_mts', 'fondo_mts', 'habitaciones', 'banos', 'es_esquina', 'nro_lote',
                'colinda_norte', 'colinda_sur', 'colinda_este', 'colinda_oeste', 'activo', 'caracteristicas', 'caracteristica_ids', 'referencia', 'url_maps', 'latitud', 'longitud']);
        }
        $started = microtime(true);
        try {
            $response = $this->clients->make('propiedades')->request($method, $path, $options);
        } catch (GuzzleException $e) {
            throw new IntegrationException('Propiedades no está disponible.', 503, 'SERVICE_UNAVAILABLE');
        } finally {
            foreach ($options['multipart'] ?? [] as $part) {
                if (is_resource($part['contents'])) {
                    fclose($part['contents']);
                }
            }
        }
        $status = $response->getStatusCode();
        Log::info('catalog.request', ['operation' => $method.' '.preg_replace('~/[0-9a-fA-F-]{1,}(?=/|$)~', '/:id', $path),
            'status' => $status, 'duration_ms' => round((microtime(true) - $started) * 1000, 2), 'correlation_id' => $headers['X-Correlation-ID']]);
        if ($bytes && $status === 200) {
            return ['status' => 200, 'bytes' => (string) $response->getBody(), 'content_type' => $response->getHeaderLine('Content-Type')];
        }
        $body = json_decode((string) $response->getBody(), true);
        if ($status >= 200 && $status < 300 && is_array($body)) {
            return ['status' => $status, 'body' => $body, 'etag' => $response->getHeaderLine('ETag'), 'replay' => $response->getHeaderLine('Idempotency-Replayed')];
        }
        $mapped = in_array($status, [401, 403, 404, 409, 412, 413, 422, 428, 429], true) ? $status : ($status === 400 ? 422 : 503);
        $messages = [401 => 'La sesión ya no está vigente.', 403 => 'No tienes permiso.', 404 => 'Recurso no encontrado.', 409 => 'Los datos o la disponibilidad cambiaron.',
            412 => 'La propiedad cambió. Actualiza antes de guardar.', 413 => 'El archivo o lote es demasiado grande.', 422 => 'Verifica los datos proporcionados.', 428 => 'Vuelve a cargar la propiedad.', 429 => 'Demasiadas solicitudes.'];
        $errors = [];
        foreach (is_array($body['errors'] ?? null) ? $body['errors'] : [] as $key => $unused) {
            $key = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', str_replace('$.', '', $key)));
            if (preg_match('/^[a-z_]+(?:\.[0-9]+)?$/D', $key)) {
                $errors[$key] = ['El valor proporcionado no es válido.'];
            }
        }
        $code = $mapped === 503 ? 'SERVICE_UNAVAILABLE' : (in_array($body['code'] ?? '', ['IDEMPOTENCY_KEY_REUSED', 'DISPONIBILIDAD_GESTIONADA', 'RESERVA_EN_CONFLICTO', 'VERSION_CONFLICT'], true) ? $body['code'] : 'CATALOG_REQUEST_REJECTED');
        throw new IntegrationException($messages[$mapped] ?? 'Propiedades no está disponible.', $mapped, $code, $errors);
    }
}

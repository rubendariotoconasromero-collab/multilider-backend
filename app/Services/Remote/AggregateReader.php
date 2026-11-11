<?php

namespace App\Services\Remote;

use App\Auth\RemotePrincipal;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AggregateReader
{
    public function __construct(
        private BusinessGateway $business,
        private CatalogClient $catalog,
        private IdentityClient $identity,
        private HttpClientFactory $clients,
    ) {}

    public function read(Request $request, string $service, string $path, array $filters = []): array
    {
        $request->headers->set('X-Correlation-ID', Str::isUuid($request->header('X-Correlation-ID') ?? '')
            ? $request->header('X-Correlation-ID') : (string) Str::uuid());
        try {
            return match ($service) {
                'propiedades' => $this->catalog->request($request->duplicate($filters, []), 'GET', $path,
                    str_starts_with($path, 'api/public/'))['body'],
                'seguridad' => $this->identity->request('GET', $path, $filters, $this->credential($request)),
                'comercial', 'finanzas' => $this->business->send($request, $service, 'GET', $path, $filters)['body'],
                default => throw new \LogicException('Lector remoto no configurado.'),
            };
        } catch (IntegrationException $error) {
            if ($error->status !== 503) {
                throw $error;
            }
            throw new IntegrationException("No se pudo completar la consulta en $service.", 503, 'SERVICE_UNAVAILABLE');
        }
    }

    public function all(Request $request, string $service, string $path, array $filters = [], int $size = 100): Collection
    {
        $result = collect();
        $total = null;
        for ($page = 1; $page <= 50; $page++) {
            $packet = $this->read($request, $service, $path, $filters + ['page' => $page, 'per_page' => $size, 'size' => $size]);
            $meta = $packet['meta'] ?? $packet;
            if (! isset($meta['total'], $meta['last_page']) || ! is_array($packet['data'] ?? null)) {
                throw new IntegrationException("Respuesta incompleta de $service.");
            }
            $total ??= (int) $meta['total'];
            if ($total !== (int) $meta['total']) {
                throw new IntegrationException('Los datos cambiaron durante la consulta. Vuelve a consultar.', 503, 'SERVICE_UNAVAILABLE');
            }
            if ((int) $meta['last_page'] > 50) {
                throw new IntegrationException('Acota los filtros para consultar este volumen de datos.', 422);
            }
            $result = $result->concat($packet['data']);
            if ($page === (int) $meta['last_page']) {
                if ($result->unique('id')->count() !== $total || $result->count() !== $total) {
                    throw new IntegrationException('La consulta remota no devolvió el conjunto completo.');
                }

                return $result;
            }
        }
        throw new IntegrationException('La consulta remota no devolvió el conjunto completo.');
    }

    public function publicAdvisers(): array
    {
        $rows = json_decode($this->publicContent('api/lecturas/asesores-publicos', 256 * 1024)['body'], true);
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new IntegrationException('Respuesta pública de asesores inválida.');
        }

        return array_map(fn (array $row) => [
            'id' => (int) $row['id'], 'nombre_completo' => $row['nombre_completo'],
            'telefono' => $row['telefono'],
            'foto' => empty($row['foto']) ? null : '/api/landing/asesores/'.(int) $row['id'].'/foto',
        ], $rows);
    }

    public function publicPhoto(int $id)
    {
        $result = $this->publicContent("api/lecturas/asesores-publicos/$id/foto", 2 * 1024 * 1024);
        abort_unless(in_array($result['type'], ['image/png', 'image/jpeg', 'image/gif'], true), 404);

        return response($result['body'], 200, ['Content-Type' => $result['type'], 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store']);
    }

    private function publicContent(string $path, int $limit): array
    {
        try {
            $response = $this->clients->make('comercial')->get($path);
        } catch (GuzzleException) {
            throw new IntegrationException('Comercial no está disponible.', 503, 'SERVICE_UNAVAILABLE');
        }
        abort_if($response->getStatusCode() === 404, 404);
        $bytes = $response->getBody()->read($limit + 1);
        if ($response->getStatusCode() !== 200 || strlen($bytes) > $limit) {
            throw new IntegrationException('Contenido público de Comercial no disponible.', 503, 'SERVICE_UNAVAILABLE');
        }

        return ['body' => $bytes, 'type' => $response->getHeaderLine('Content-Type')];
    }

    private function credential(Request $request): string
    {
        $principal = $request->user();
        abort_unless($principal instanceof RemotePrincipal, 401);

        return $principal->credential();
    }
}

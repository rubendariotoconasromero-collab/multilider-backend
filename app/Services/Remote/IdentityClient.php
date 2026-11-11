<?php

namespace App\Services\Remote;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class IdentityClient
{
    public function __construct(private HttpClientFactory $clients) {}

    public function request(string $method, string $path, array $data = [], ?string $token = null): array
    {
        if (! preg_match('~^api/(auth|usuario|rol|permiso|rolpermiso)(/|$)~', $path)) {
            throw new \InvalidArgumentException('Ruta de identidad desconocida.');
        }
        $headers = ['X-Correlation-ID' => (string) Str::uuid()];
        $correlation = request()->header('X-Correlation-ID');
        if (Str::isUuid($correlation ?? '')) {
            $headers['X-Correlation-ID'] = $correlation;
        }
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        $started = microtime(true);
        try {
            $response = $this->clients->make('seguridad')->request($method, $path,
                ['headers' => $headers] + ($data !== [] ? [$method === 'GET' ? 'query' : 'json' => $data] : []));
        } catch (GuzzleException $e) {
            $this->record($method, $path, 503, $started, $headers['X-Correlation-ID']);
            throw new IntegrationException('Seguridad no está disponible.', 503, 'SERVICE_UNAVAILABLE');
        }
        $status = $response->getStatusCode();
        $this->record($method, $path, $status, $started, $headers['X-Correlation-ID']);
        $body = json_decode((string) $response->getBody(), true);
        if ($status >= 200 && $status < 300 && is_array($body)) {
            return $body;
        }
        $messages = [401 => 'Las credenciales son incorrectas o la sesión ya no está vigente.',
            403 => 'No tienes permiso para realizar esta acción.', 404 => 'Recurso no encontrado.',
            409 => 'Conflicto de identidad; vuelve a consultar el estado.', 422 => 'Verifica los datos proporcionados.',
            429 => 'Demasiados intentos. Inténtalo nuevamente en un minuto.'];
        $mapped = isset($messages[$status]) ? $status : 503;
        if ($status === 400) {
            $mapped = 422;
        }
        $errors = [];
        $allowed = ['nombre', 'correo', 'password', 'password_actual', 'password_nuevo', 'password_nuevo_confirmation', 'rol_permiso_ids', 'permisos'];
        foreach (is_array($body['errors'] ?? null) ? $body['errors'] : [] as $field => $unused) {
            $field = strtolower($field);
            if (in_array($field, $allowed, true)) {
                $errors[$field] = ['El valor proporcionado no es válido.'];
            }
        }
        throw new IntegrationException($messages[$mapped] ?? 'Seguridad no está disponible.', $mapped,
            $mapped === 503 ? 'SERVICE_UNAVAILABLE' : 'IDENTITY_REQUEST_REJECTED', $errors);
    }

    private function record(string $method, string $path, int $status, float $started, string $correlation): void
    {
        Log::info('identity.request', ['service' => 'seguridad',
            'operation' => $method.' '.preg_replace('~/[0-9]+(?=/|$)~', '/:id', $path), 'status' => $status,
            'duration_ms' => round((microtime(true) - $started) * 1000, 2), 'correlation_id' => $correlation]);
    }
}

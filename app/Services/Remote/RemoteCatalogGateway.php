<?php

namespace App\Services\Remote;

use App\Contracts\Architecture\CatalogGateway;
use App\Contracts\Architecture\DelegatedTokenProvider;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as PaginatorContract;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class RemoteCatalogGateway implements CatalogGateway
{
    public function __construct(private HttpClientFactory $clients, private DelegatedTokenProvider $tokens) {}

    public function properties(Request $request): PaginatorContract
    {
        $query = $request->validate([
            'search' => 'nullable|string', 'ciudad_id' => 'nullable|integer|min:1',
            'distrito_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:1000',
        ]);
        $token = $this->tokens->forRequest($request, 'propiedades');
        // Structural check only; verification is the delegated provider's responsibility.
        if (! preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token)) {
            throw new IntegrationException('Se requiere una credencial delegada válida.');
        }
        $correlation = $request->header('X-Correlation-ID');
        $headers = ['Authorization' => 'Bearer '.$token,
            'X-Correlation-ID' => Str::isUuid($correlation ?? '') ? $correlation : (string) Str::uuid()];
        if (Str::isUuid($request->header('Idempotency-Key', ''))) {
            $headers['Idempotency-Key'] = $request->header('Idempotency-Key');
        }
        try {
            $response = $this->clients->make('propiedades')->request('GET', 'api/propiedades', [
                'query' => $query, 'headers' => $headers,
            ]);
        } catch (GuzzleException $e) {
            throw new IntegrationException('El servicio Propiedades no está disponible.', 503, 'SERVICE_UNAVAILABLE');
        }
        $status = $response->getStatusCode();
        try {
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $body = null;
        }
        if ($status !== 200) {
            // Known public messages; remote detail/title can contain SQL, URLs or credentials.
            $messages = [401 => 'Autenticación requerida.', 403 => 'Acceso denegado.', 404 => 'Recurso no encontrado.',
                409 => 'Conflicto con el estado actual.', 422 => 'Los datos proporcionados no son válidos.'];
            $mapped = isset($messages[$status]) ? $status : 503;
            // ASP.NET validation ProblemDetails uses 400; preserve Laravel's validation status.
            if ($status === 400 && is_array($body['errors'] ?? null)) {
                $mapped = 422;
            }
            $errors = [];
            foreach (($mapped === 422 && is_array($body['errors'] ?? null) ? $body['errors'] : []) as $field => $unused) {
                if (array_key_exists($field, ['search' => 1, 'ciudad_id' => 1, 'distrito_id' => 1, 'page' => 1, 'per_page' => 1])) {
                    $errors[$field] = ['El valor proporcionado no es válido.'];
                }
            }
            throw new IntegrationException($messages[$mapped] ?? 'El servicio Propiedades no está disponible.', $mapped,
                $mapped === 503 ? 'SERVICE_UNAVAILABLE' : 'REMOTE_REQUEST_REJECTED', $errors);
        }
        if (! is_array($body) || ! isset($body['data'], $body['total'], $body['current_page'], $body['per_page'])
            || ! is_array($body['data']) || ! array_is_list($body['data']) || ! is_int($body['total']) || $body['total'] < 0
            || ! is_int($body['per_page']) || $body['per_page'] < 1 || ! is_int($body['current_page']) || $body['current_page'] < 1) {
            throw new IntegrationException('Respuesta inválida del servicio Propiedades.', 503, 'INVALID_SERVICE_RESPONSE');
        }
        $items = [];
        foreach ($body['data'] as $row) {
            if (! is_array($row)) {
                throw new IntegrationException('Propiedad remota inválida.', 503, 'INVALID_SERVICE_RESPONSE');
            }
            $items[] = PropertyResponseAdapter::fromRemote($row);
        }

        return new LengthAwarePaginator($items, $body['total'], $body['per_page'], $body['current_page'], [
            'path' => $request->url(), 'pageName' => 'page',
        ]);
    }
}

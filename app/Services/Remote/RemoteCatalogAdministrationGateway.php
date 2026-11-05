<?php

namespace App\Services\Remote;

use App\Contracts\Architecture\CatalogAdministrationGateway;
use App\Services\Local\LocalCatalogAdministrationGateway;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class RemoteCatalogAdministrationGateway implements CatalogAdministrationGateway
{
    public function __construct(private CatalogClient $client) {}

    public function handle(Request $request, Closure $local)
    {
        $route = LocalCatalogAdministrationGateway::authorize($request);
        // Keep the characterized listing gateway and its contract tests.
        if ($request->path() === 'api/propiedades' && in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $local($request);
        }
        if ($request->isMethod('POST') && str_ends_with($request->path(), '/imagenes')) {
            $request->validate(['imagenes' => 'required|array|min:1|max:10', 'imagenes.*' => 'image|mimes:jpeg,jpg,png,gif|max:2048']);
        }
        $method = $request->isMethod('HEAD') ? 'GET' : $request->method();
        if ($method === 'PATCH' && ! str_ends_with($route['target'], '/principal')) {
            $method = 'PUT';
        }
        $result = $this->client->request($request, $method, $route['target']);
        $body = self::adapt($result['body']);
        $status = $result['status'];
        if ($request->isMethod('POST') && str_ends_with($request->path(), '/imagenes')) {
            $body = ['message' => 'Imágenes subidas correctamente', 'imagenes' => $body['data'] ?? []];
            $status = 201;
        } elseif ($request->isMethod('POST') && $request->path() === 'api/ubicaciones') {
            $status = 201;
        }
        if (isset($body['data'], $body['total'], $body['per_page'], $body['current_page']) && is_array($body['data'])) {
            $body = new LengthAwarePaginator($body['data'], $body['total'], $body['per_page'], $body['current_page'], ['path' => $request->url(), 'pageName' => 'page']);
        }
        $response = response()->json($body, $status);
        if ($result['etag']) {
            $response->header('ETag', $result['etag']);
        }
        if ($result['replay']) {
            $response->header('Idempotency-Replayed', $result['replay']);
        }

        return $response;
    }

    public static function adapt(array $data, bool $public = false): array
    {
        if (isset($data['id'], $data['precio_venta'], $data['superficie_m2'])) {
            return PropertyResponseAdapter::fromRemote($data, $public);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::adapt($value, $public);
            } elseif (in_array($key, ['estado', 'activo', 'es_principal', 'es_esquina', 'es_destacado'], true) && is_bool($value)) {
                $data[$key] = (int) $value;
            } elseif ($key === 'url' && is_string($value)) {
                $data[$key] = self::imageUrl($value);
            }
        }

        return $data;
    }

    public static function imageUrl(string $url): string
    {
        return preg_match('~^/api/public/catalogo/imagenes/([a-fA-F0-9-]{36})/([a-fA-F0-9]{64})$~D', $url, $m)
            ? '/catalogo/media/'.$m[1].'/'.$m[2] : $url;
    }
}

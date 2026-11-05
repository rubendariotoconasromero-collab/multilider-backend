<?php

namespace App\Services\Local;

use App\Architecture\CatalogRoutes;
use App\Contracts\Architecture\CatalogAdministrationGateway;
use App\Models\Caracteristica;
use App\Models\Ciudad;
use App\Models\Distrito;
use App\Models\Propietario;
use App\Models\SectorUrbano;
use App\Services\Remote\IntegrationException;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LocalCatalogAdministrationGateway implements CatalogAdministrationGateway
{
    public static function authorize(Request $request): array
    {
        $route = CatalogRoutes::describe($request);
        if (! $route) {
            throw new IntegrationException('Operación de catálogo desconocida.');
        }
        if ($route['permission']) {
            abort_unless($request->user()?->hasPermission($route['permission']), 403, 'No tienes permiso para realizar esta acción.');
        }

        return $route;
    }

    public function handle(Request $request, Closure $local)
    {
        $route = self::authorize($request);
        if ($request->path() === 'api/catalogo/opciones-propiedad') {
            return $this->options($request);
        }
        $owner = hash('sha256', 'local|'.$request->user()->id);
        if (str_starts_with($request->path(), 'api/catalogo/operaciones/')) {
            $row = DB::table('catalog_commands')->where('owner', $owner)->where('command_id', $request->segment(4))->first();
            abort_unless($row, 404);
            abort_unless($request->user()->hasPermission($row->permission), 403);

            return response()->json(['command_id' => $row->command_id, 'status' => $row->status, 'result' => json_decode($row->result, true), 'etag' => $row->etag]);
        }
        $run = function () use ($request, $route, $local) {
            if ($route['kind'] === 'propiedades' && (in_array($request->method(), ['PUT', 'PATCH'], true) || $request->isMethod('POST') && $request->path() === 'api/propiedades')) {
                return app(LocalPropertyWriter::class)->save($request, $route['id'] ? (int) $route['id'] : null);
            }
            $response = $local($request);
            if ($route['kind'] === 'propiedades' && $response instanceof JsonResponse && $request->isMethod('GET')) {
                $data = $response->getData(true);
                if (isset($data['data']) && is_array($data['data'])) {
                    $data['data'] = array_map(fn ($r) => PropertyVersion::annotate($r), $data['data']);
                } elseif (isset($data['precio_venta'])) {
                    $data = PropertyVersion::annotate($data);
                    $response->headers->set('ETag', '"'.$data['version'].'"');
                }
                $response->setData($data);
            }

            return $response;
        };
        if (in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $run();
        }
        $key = $request->header('Idempotency-Key');
        if ($key !== null && (! Str::isUuid($key) || $key === '00000000-0000-0000-0000-000000000000')) {
            throw new IntegrationException('Idempotency-Key debe ser un UUID.', 422, 'VALIDATION_ERROR');
        }
        if ($key !== null) {
            $key = strtolower($key);
        }
        $payload = $request->except(array_keys($request->files->all()));
        $this->sort($payload);
        $files = [];
        foreach ($request->allFiles() as $group) {
            foreach ((array) $group as $file) {
                $files[] = hash_file('sha256', $file->getRealPath());
            }
        } sort($files);
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->header('If-Match').'|'.json_encode([$payload, $files], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($route, $run, $owner, $key, $hash) {
                DB::table('catalog_mutex')->where('id', 1)->lockForUpdate()->first();
                $id = hash('sha256', $owner.'|'.$key);
                if ($key && ($old = DB::table('catalog_commands')->where('key', $id)->first())) {
                    if ($old->request_hash !== $hash) {
                        throw new IntegrationException('La clave ya identifica otros datos.', 409, 'IDEMPOTENCY_KEY_REUSED');
                    }
                    $response = response()->json(json_decode($old->result, true), $old->status)->header('Idempotency-Replayed', 'true');
                    if ($old->etag) {
                        $response->header('ETag', $old->etag);
                    }

                    return $response;
                }
                $response = $run();
                if ($key && $response instanceof JsonResponse && $response->isSuccessful()) {
                    DB::table('catalog_commands')->insert([
                        'key' => $id, 'owner' => $owner, 'command_id' => strtolower($key), 'request_hash' => $hash, 'permission' => $route['permission'],
                        'status' => $response->getStatusCode(), 'result' => $response->getContent(), 'etag' => $response->headers->get('ETag'), 'created_at' => now(), 'updated_at' => now()]);
                }

                return $response;
            });
        } catch (QueryException $e) {
            throw new IntegrationException('Los datos cambiaron o existe una referencia duplicada.', 409, 'CATALOG_CONFLICT');
        }
    }

    private function sort(array &$value): void
    {
        ksort($value);
        $decimals = ['precio_venta', 'superficie_m2', 'superficie_construida_m2', 'frente_mts', 'fondo_mts'];
        foreach ($value as $key => &$item) {
            if (is_array($item)) {
                $this->sort($item);
            } elseif (in_array($key, $decimals, true) && $item !== null && is_numeric($item)) {
                $text = is_string($item) ? $item : json_encode($item, JSON_THROW_ON_ERROR);
                if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/D', $text, $m)) {
                    $whole = ltrim($m[2], '0') ?: '0';
                    $fraction = rtrim($m[3] ?? '', '0');
                    $item = (($whole !== '0' || $fraction !== '') ? $m[1] : '').$whole.($fraction !== '' ? '.'.$fraction : '');
                }
            }
        }
    }

    private function options(Request $request)
    {
        $data = $request->validate(['kind' => 'required|in:propietarios,distritos,ciudades,sectores,caracteristicas', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:1000']);
        $query = match ($data['kind']) {
            'propietarios' => Propietario::select('id', 'tipo', 'nombre_completo', 'nombre_empresa', 'estado')->where('estado', true)->orderBy('nombre_completo'),
            'distritos' => Distrito::select('id', 'nombre', 'ciudad_id', 'estado')->with('ciudad:id,nombre')->where('estado', true)->orderBy('nombre'),
            'ciudades' => Ciudad::select('id', 'nombre', 'departamento', 'estado')->where('estado', true)->orderBy('nombre'),
            'sectores' => SectorUrbano::select('id', 'nombre', 'distrito_id', 'tipo', 'uv', 'manzano', 'estado')->where('estado', true)->orderBy('nombre'),
            'caracteristicas' => Caracteristica::select('id', 'nombre', 'tipo', 'estado')->where('estado', true)->orderBy('nombre'),
        };

        return response()->json(['opciones' => $query->paginate($data['per_page'] ?? 1000), 'capabilities' => ['can_edit_estado' => true]]);
    }
}

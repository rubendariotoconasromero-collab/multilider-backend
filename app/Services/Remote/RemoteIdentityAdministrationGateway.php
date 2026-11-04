<?php

namespace App\Services\Remote;

use App\Contracts\Architecture\IdentityAdministrationGateway;
use App\Services\Local\LocalIdentityAdministrationGateway;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class RemoteIdentityAdministrationGateway implements IdentityAdministrationGateway
{
    public function __construct(private IdentityClient $client) {}

    public function handle(Request $request, Closure $local)
    {
        LocalIdentityAdministrationGateway::authorize($request);
        $kind = $request->segment(2);
        if ($kind === 'asignar-permisos' && in_array($request->method(), ['PUT', 'PATCH'], true)) {
            throw new IntegrationException('Actualización de combinación rol/permiso no implementada; usa la sincronización de roles.');
        }
        $map = ['usuarios' => 'usuario', 'roles' => 'rol', 'permisos' => 'permiso', 'asignar-permisos' => 'rolpermiso'];
        $tail = implode('/', array_slice($request->segments(), 2));
        if (! preg_match('~^(?:[1-9][0-9]*(?:/(?:asignaciones(?:/sync)?|permisos(?:/sync)?))?)?$~D', $tail)) {
            throw new IntegrationException('Operación administrativa no disponible.');
        }
        $data = $request->only(['nombre', 'correo', 'password', 'estado', 'descripcion', 'rol_id', 'permiso_id', 'rol_permiso_ids', 'permisos']);
        if (array_key_exists('rol_permiso_ids', $data)) {
            $data['rolPermisoIds'] = $data['rol_permiso_ids'];
            unset($data['rol_permiso_ids']);
        }
        if (array_key_exists('rol_id', $data)) {
            $data['rolId'] = $data['rol_id'];
            unset($data['rol_id']);
        }
        if (array_key_exists('permiso_id', $data)) {
            $data['permisoId'] = $data['permiso_id'];
            unset($data['permiso_id']);
        }
        $path = 'api/'.$map[$kind].($tail ? '/'.$tail : '');
        if ($kind === 'usuarios') {
            $path = preg_replace('~/asignaciones/sync$~', '/asignaciones', $path);
        }
        $method = $request->isMethod('PATCH') ? 'PUT' : ($request->isMethod('HEAD') ? 'GET' : $request->method());
        $body = $this->client->request($method, $path, $data, $request->user()->credential());
        $normalize = function ($value) use (&$normalize, $kind) {
            if (! is_array($value)) {
                return $value;
            }
            if (isset($value['id'], $value['correo']) && $kind === 'usuarios') {
                return IdentityResponseMapper::user($value, null, false);
            }
            if ($kind === 'asignar-permisos' && isset($value['rolId'], $value['permisoId'])) {
                return ['id' => $value['id'], 'rol_id' => $value['rolId'], 'permiso_id' => $value['permisoId'],
                    'rol' => ['id' => $value['rolId'], 'nombre' => $value['nombreRol']], 'permiso' => ['id' => $value['permisoId'], 'nombre' => $value['nombrePermiso']]];
            }
            $result = [];
            foreach ($value as $key => $item) {
                $result[is_string($key) ? Str::snake($key) : $key] = $normalize($item);
            }

            return $result;
        };

        return response()->json($normalize($body), $request->isMethod('POST') && $tail === '' ? 201 : 200);
    }
}

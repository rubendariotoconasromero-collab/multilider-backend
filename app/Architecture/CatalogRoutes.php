<?php

namespace App\Architecture;

use Illuminate\Http\Request;

final class CatalogRoutes
{
    public const PERMISSIONS = ['propiedades' => 'acceso_propiedades', 'propietarios' => 'acceso_propietarios',
        'ubicaciones' => 'acceso_propiedades', 'ciudades' => 'acceso_ciudades', 'distritos' => 'acceso_zonas',
        'sectores-urbanos' => 'acceso_zonas', 'caracteristicas' => 'acceso_caracteristicas'];

    public static function describe(Request $request): ?array
    {
        $path = $request->path();
        $method = $request->isMethod('HEAD') ? 'GET' : $request->method();
        if (preg_match('~^api/(propiedades|propietarios|ubicaciones|ciudades|distritos|sectores-urbanos|caracteristicas)(?:/([1-9][0-9]*))?$~D', $path, $m)) {
            $id = $m[2] ?? null;
            if ($method === 'GET' || (! $id && $method === 'POST') || ($id && in_array($method, ['PUT', 'PATCH', 'DELETE'], true))) {
                return ['kind' => $m[1], 'permission' => self::PERMISSIONS[$m[1]], 'id' => $id, 'target' => $path];
            }
        }
        if ($method === 'GET' && preg_match('~^api/sectores-urbanos/por-distrito/([1-9][0-9]*)$~D', $path)) {
            return ['kind' => 'sectores-urbanos', 'permission' => 'acceso_zonas', 'id' => null, 'target' => $path];
        }
        if ($method === 'POST' && preg_match('~^api/propiedades/([1-9][0-9]*)/(imagenes|caracteristicas/sync)$~D', $path, $m)) {
            return ['kind' => 'propiedades', 'permission' => 'acceso_propiedades', 'id' => $m[1], 'target' => $path];
        }
        if (preg_match('~^api/imagenes-propiedades/([1-9][0-9]*)(/principal)?$~D', $path, $m)
            && (($method === 'DELETE' && empty($m[2])) || ($method === 'PATCH' && ! empty($m[2])))) {
            return ['kind' => 'imagenes', 'permission' => 'acceso_propiedades', 'id' => $m[1], 'target' => 'api/propiedades/imagenes/'.$m[1].($m[2] ?? '')];
        }
        if ($method === 'GET' && ($path === 'api/catalogo/opciones-propiedad' || preg_match('~^api/catalogo/operaciones/[a-fA-F0-9-]{36}$~D', $path))) {
            return ['kind' => 'catalogo', 'permission' => $path === 'api/catalogo/opciones-propiedad' ? 'acceso_propiedades' : null, 'id' => null, 'target' => $path];
        }

        return null;
    }

    public static function publicTarget(Request $request): ?string
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return null;
        }

        return preg_match('~^api/landing/(propiedades(?:/[1-9][0-9]*(?:/similares)?)?|ciudades|distritos|sectores-urbanos/[1-9][0-9]*)$~D', $request->path(), $m)
            ? 'api/public/catalogo/'.$m[1] : null;
    }
}

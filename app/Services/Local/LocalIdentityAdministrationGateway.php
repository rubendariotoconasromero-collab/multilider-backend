<?php

namespace App\Services\Local;

use App\Contracts\Architecture\IdentityAdministrationGateway;
use Closure;
use Illuminate\Http\Request;

class LocalIdentityAdministrationGateway implements IdentityAdministrationGateway
{
    public static function permission(Request $request): string
    {
        return match ($request->segment(2)) {
            'usuarios' => 'acceso_usuarios', 'roles', 'asignar-permisos' => 'acceso_roles', 'permisos' => 'acceso_permisos',
        };
    }

    public function handle(Request $request, Closure $local)
    {
        self::authorize($request);

        return $local($request);
    }

    public static function authorize(Request $request): void
    {
        $catalogRead = $request->segment(2) === 'asignar-permisos' && in_array($request->method(), ['GET', 'HEAD'], true);
        abort_unless($request->user()?->hasPermission(self::permission($request))
            || ($catalogRead && $request->user()?->hasPermission('acceso_usuarios')), 403, 'No tienes permiso para realizar esta acción.');
    }
}

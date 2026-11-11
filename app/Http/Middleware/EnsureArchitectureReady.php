<?php

namespace App\Http\Middleware;

use App\Architecture\ArchitectureMode;
use App\Architecture\CatalogRoutes;
use App\Services\Remote\IntegrationException;
use Closure;
use Illuminate\Http\Request;

final class EnsureArchitectureReady
{
    public function __construct(private ArchitectureMode $mode) {}

    public function handle(Request $request, Closure $next)
    {
        if (! $this->mode->isLocal()
            && ! \App\Architecture\AggregateRoutes::enabled($request)
            && ! (config('architecture.business_enabled') && \App\Architecture\BusinessRoutes::describe($request))
            && ! (config('architecture.business_enabled') && $request->isMethod('GET') && $request->path() === 'api/comercial/opciones')
            && ! (config('architecture.business_enabled') && \App\Architecture\PublicPaymentRoutes::target($request))
            && ! CatalogRoutes::describe($request)
            && ! CatalogRoutes::publicTarget($request)
            && ! (in_array($request->method(), ['GET', 'HEAD'], true) && preg_match('~^api/catalogo/imagenes/[1-9][0-9]*/contenido$~D', $request->path()))
            && ! ($request->isMethod('GET') && $request->path() === 'api/resolver-url-mapa')
            && ! in_array($request->method().' '.$request->path(), [
                'POST api/login', 'POST api/logout', 'GET api/me', 'GET api/perfil',
                'PUT api/perfil', 'PUT api/perfil/cambiar-password',
                'GET api/empresa/datos', 'POST api/empresa',
            ], true)
            && ! $request->is('api/perfil/correo/*')
            && ! preg_match('~^api/(usuarios|roles|permisos|asignar-permisos)(/|$)~', $request->path())) {
            // Laravel continues to own dashboard/reports/company/mail/portal.
            // Business adapters still pending in F4-F8 must not silently read local data.
            throw new IntegrationException('Esta operación todavía no está disponible en modo microservicios.');
        }

        return $next($request);
    }
}

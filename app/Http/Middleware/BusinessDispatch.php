<?php

namespace App\Http\Middleware;

use App\Architecture\ArchitectureMode;
use App\Architecture\BusinessRoutes;
use App\Auth\RemotePrincipal;
use App\Services\Remote\BusinessGateway;
use Closure;
use Illuminate\Http\Request;

final class BusinessDispatch
{
    public function __construct(private ArchitectureMode $mode) {}
    public function handle(Request $request, Closure $next)
    {
        if ($this->mode->isLocal() || ! ($route = BusinessRoutes::describe($request))) {
            return $next($request);
        }
        abort_unless($request->user() instanceof RemotePrincipal, 401);
        if ($route['permission']) { abort_unless($request->user()->hasPermission($route['permission']), 403); }
        return app(BusinessGateway::class)->handle($request, $route);
    }
}

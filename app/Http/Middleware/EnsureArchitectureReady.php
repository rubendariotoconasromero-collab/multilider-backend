<?php

namespace App\Http\Middleware;

use App\Architecture\ArchitectureMode;
use App\Services\Remote\IntegrationException;
use Closure;
use Illuminate\Http\Request;

final class EnsureArchitectureReady
{
    public function __construct(private ArchitectureMode $mode) {}

    public function handle(Request $request, Closure $next)
    {
        if (! $this->mode->isLocal()
            && ! ($request->isMethod('GET') && $request->path() === 'api/propiedades')) {
            // Fail closed for ALL other API routes, including login, landing, public callbacks,
            // mail and reports: their ownership/authentication is completed in later phases.
            throw new IntegrationException('Esta operación todavía no está disponible en modo microservicios.');
        }

        return $next($request);
    }
}

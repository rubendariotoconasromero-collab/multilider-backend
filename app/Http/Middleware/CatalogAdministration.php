<?php

namespace App\Http\Middleware;

use App\Contracts\Architecture\CatalogAdministrationGateway;
use Closure;
use Illuminate\Http\Request;

final class CatalogAdministration
{
    public function __construct(private CatalogAdministrationGateway $catalog) {}

    public function handle(Request $request, Closure $next)
    {
        return $this->catalog->handle($request, $next);
    }
}

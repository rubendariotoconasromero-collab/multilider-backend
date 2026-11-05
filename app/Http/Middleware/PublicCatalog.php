<?php

namespace App\Http\Middleware;

use App\Contracts\Architecture\PublicCatalogGateway;
use Closure;
use Illuminate\Http\Request;

final class PublicCatalog
{
    public function __construct(private PublicCatalogGateway $catalog) {}

    public function handle(Request $request, Closure $next)
    {
        return $this->catalog->handle($request, $next);
    }
}

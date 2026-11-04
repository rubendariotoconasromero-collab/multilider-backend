<?php

namespace App\Http\Middleware;

use App\Contracts\Architecture\IdentityAdministrationGateway;
use Closure;
use Illuminate\Http\Request;

final class IdentityAdministration
{
    public function __construct(private IdentityAdministrationGateway $gateway) {}

    public function handle(Request $request, Closure $next)
    {
        return $this->gateway->handle($request, $next);
    }
}

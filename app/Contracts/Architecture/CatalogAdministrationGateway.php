<?php

namespace App\Contracts\Architecture;

use Closure;
use Illuminate\Http\Request;

interface CatalogAdministrationGateway
{
    public function handle(Request $request, Closure $local);
}

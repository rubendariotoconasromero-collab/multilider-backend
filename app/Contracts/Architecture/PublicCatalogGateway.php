<?php

namespace App\Contracts\Architecture;

use Closure;
use Illuminate\Http\Request;

interface PublicCatalogGateway
{
    public function handle(Request $request, Closure $local);
}

<?php

namespace App\Contracts\Architecture;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

interface CatalogGateway
{
    public function properties(Request $request): LengthAwarePaginator;
}

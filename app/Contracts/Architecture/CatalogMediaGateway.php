<?php

namespace App\Contracts\Architecture;

use Illuminate\Http\Request;

interface CatalogMediaGateway
{
    public function content(Request $request, string $id, ?string $revision = null);
}

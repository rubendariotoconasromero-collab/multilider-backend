<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Architecture\CatalogAdministrationGateway;
use App\Contracts\Architecture\CatalogMediaGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class CatalogController extends Controller
{
    public function dispatch(Request $request, CatalogAdministrationGateway $catalog)
    {
        return $catalog->handle($request, fn () => abort(404));
    }

    public function image(Request $request, CatalogMediaGateway $media, string $id)
    {
        return $media->content($request, $id);
    }

    public function publicImage(Request $request, CatalogMediaGateway $media, string $id, string $revision)
    {
        return $media->content($request, $id, $revision);
    }
}

<?php

namespace App\Services\Local;

use App\Contracts\Architecture\CatalogMediaGateway;
use App\Models\ImagenPropiedad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class LocalCatalogMediaGateway implements CatalogMediaGateway
{
    public function content(Request $request, string $id, ?string $revision = null)
    {
        if ($revision !== null) {
            $image = ImagenPropiedad::where('public_id', $id)->where('content_hash', $revision)->where('publicada', true)
                ->whereHas('propiedad', fn ($q) => $q->where('activo', true))->firstOrFail();
        } else {
            abort_unless($request->user()?->hasPermission('acceso_propiedades') && ctype_digit($id), 403);
            $image = ImagenPropiedad::findOrFail($id);
        }
        if ($image->storage_key) {
            abort_unless(preg_match('~^catalog-media/objects/f4-[a-f0-9-]{36}\.(jpg|jpeg|png|gif)$~D', $image->storage_key), 404);
            $root = realpath(Storage::disk('local')->path('catalog-media'));
            $absolute = realpath(Storage::disk('local')->path($image->storage_key));
            abort_unless($root && $absolute && str_starts_with($absolute, $root.DIRECTORY_SEPARATOR) && is_file($absolute), 404);

            return response()->file($absolute, ['Content-Type' => $image->content_type, 'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => $revision === null ? 'private, no-store' : 'public, no-cache, must-revalidate']);
        }
        abort_if($revision !== null, 404);
        $url = parse_url($image->url, PHP_URL_PATH);
        abort_unless(is_string($url) && str_starts_with($url, '/storage/'), 404);
        $path = substr($url, 9);
        $root = realpath(Storage::disk('public')->path(''));
        $absolute = realpath(Storage::disk('public')->path($path));
        abort_unless($root && $absolute && str_starts_with($absolute, $root.DIRECTORY_SEPARATOR) && is_file($absolute), 404);

        return response()->file($absolute, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

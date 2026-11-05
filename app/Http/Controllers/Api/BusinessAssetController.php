<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesor;
use Illuminate\Support\Facades\Storage;

final class BusinessAssetController extends Controller
{
    public function photo(int $id)
    {
        $photo = Asesor::findOrFail($id)->foto;
        $path = is_string($photo) ? parse_url($photo, PHP_URL_PATH) : null;
        abort_unless(is_string($path) && str_starts_with($path, '/storage/'), 404);
        $relative = substr($path, strlen('/storage/'));
        abort_if(str_contains($relative, '..') || ! Storage::disk('public')->exists($relative), 404);
        return Storage::disk('public')->response($relative, null, ['Cache-Control' => 'private, no-store']);
    }
}

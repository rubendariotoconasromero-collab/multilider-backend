<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImagenPropiedad;
use App\Models\Propiedad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImagenPropiedadController extends Controller
{
    // Subir imágenes para una propiedad
    public function store(Request $request, $propiedadId)
    {
        $request->validate([
            'imagenes' => 'required|array|min:1|max:10',
            'imagenes.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $propiedad = Propiedad::findOrFail($propiedadId);
        $imagenesGuardadas = [];

        foreach ($request->file('imagenes') as $file) {
            $publicId = (string) Str::uuid();
            $hash = strtoupper(hash_file('sha256', $file->getRealPath()));
            $path = $file->storeAs('catalog-media/objects', 'f4-'.$publicId.'.'.$file->extension(), 'local');
            if (! $path) {
                throw new \RuntimeException('No se pudo almacenar la imagen.');
            }

            $imagen = ImagenPropiedad::create([
                'propiedad_id' => $propiedad->id,
                'url' => '/catalogo/media/'.$publicId.'/'.$hash,
                'public_id' => $publicId, 'storage_key' => $path, 'content_hash' => $hash,
                'content_type' => $file->getMimeType(), 'size' => $file->getSize(), 'publicada' => true,
                'es_principal' => $propiedad->imagenes()->count() == 0, // La primera es la principal por defecto
            ]);

            $imagenesGuardadas[] = $imagen;
        }

        return response()->json([
            'message' => 'Imágenes subidas correctamente',
            'imagenes' => $imagenesGuardadas,
        ], 201);
    }

    // Eliminar una imagen
    public function destroy($id)
    {
        $imagen = ImagenPropiedad::findOrFail($id);

        // Eliminar el archivo del storage
        $path = $imagen->storage_key ?: str_replace('/storage/', '', parse_url($imagen->url, PHP_URL_PATH));
        DB::table('catalog_media_cleanup')->insert(['path' => $path, 'disk' => $imagen->storage_key ? 'local' : 'public', 'created_at' => now()]);

        $principal = $imagen->es_principal;
        $propertyId = $imagen->propiedad_id;
        $imagen->delete();
        if ($principal) {
            ImagenPropiedad::where('propiedad_id', $propertyId)->update(['es_principal' => false]);
            ImagenPropiedad::where('propiedad_id', $propertyId)->orderBy('id')->first()?->update(['es_principal' => true]);
        }

        return response()->json(['message' => 'Imagen eliminada correctamente'], 200);
    }

    // Establecer una imagen como principal
    public function setPrincipal($id)
    {
        $imagen = ImagenPropiedad::findOrFail($id);

        DB::transaction(function () use ($imagen) {
            // Quitar principal a todas las demás imágenes de esta propiedad
            ImagenPropiedad::where('propiedad_id', $imagen->propiedad_id)
                ->update(['es_principal' => false]);

            // Establecer la nueva principal
            $imagen->update(['es_principal' => true]);
        });

        return response()->json(['message' => 'Imagen principal actualizada'], 200);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Architecture\CatalogGateway;
use App\Http\Controllers\Controller;
use App\Models\Propiedad;
use App\Services\Local\LocalPropertyWriter;
use Illuminate\Http\Request;

class PropiedadController extends Controller
{
    public function index(Request $request, CatalogGateway $catalog)
    {
        return response()->json($catalog->properties($request), 200);
    }

    public function store(Request $request)
    {
        return app(LocalPropertyWriter::class)->save($request);
    }

    public function show($id)
    {
        $propiedad = Propiedad::with([
            'propietarios',
            'sectorUrbano.distrito.ciudad',
            'ubicacion',
            'caracteristicas',
            'imagenes',
        ])->findOrFail($id);

        return response()->json($propiedad, 200);
    }

    public function update(Request $request, $id)
    {
        return app(LocalPropertyWriter::class)->save($request, (int) $id);
    }

    public function destroy($id)
    {
        $propiedad = Propiedad::findOrFail($id);
        $propiedad->activo = ! $propiedad->activo;
        $propiedad->save();

        $mensaje = $propiedad->activo ? 'Propiedad habilitada' : 'Propiedad inhabilitada';

        return response()->json(['message' => $mensaje, 'activo' => $propiedad->activo], 200);
    }

    public function syncCaracteristicas(Request $request, $id)
    {
        $propiedad = Propiedad::findOrFail($id);

        $validatedData = $request->validate([
            'caracteristica_ids' => 'present|array',
            'caracteristica_ids.*' => 'integer|distinct|exists:caracteristicas,id',
        ]);

        $propiedad->caracteristicas()->sync($validatedData['caracteristica_ids']);

        return response()->json(['message' => 'Características sincronizadas correctamente', 'data' => $propiedad->load('caracteristicas')]);
    }
}

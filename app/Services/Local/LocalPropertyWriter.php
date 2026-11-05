<?php

namespace App\Services\Local;

use App\Architecture\ArchitectureMode;
use App\Models\Propiedad;
use App\Models\Ubicacion;
use App\Services\Remote\IntegrationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class LocalPropertyWriter
{
    public function save(Request $request, ?int $id = null)
    {
        if (! app(ArchitectureMode::class)->isLocal()) {
            throw new IntegrationException('Escritor local no disponible en modo microservicios.');
        }

        return DB::transaction(function () use ($request, $id) {
            DB::table('catalog_mutex')->where('id', 1)->lockForUpdate()->first();
            $property = $id ? Propiedad::lockForUpdate()->findOrFail($id) : new Propiedad;
            if ($id && $request->hasHeader('If-Match') && $request->header('If-Match') !== '"'.PropertyVersion::model($property).'"') {
                throw new IntegrationException('La propiedad cambió. Actualiza antes de guardar.', 412, 'VERSION_CONFLICT');
            }
            $data = $request->validate($this->rules($id));
            if (isset($data['ubicacion'])) {
                if (! empty($data['ubicacion_id']) && (! $id || (int) $data['ubicacion_id'] !== (int) $property->ubicacion_id)) {
                    throw new IntegrationException('La ubicación no corresponde a esta propiedad.', 422, 'VALIDATION_ERROR', ['ubicacion_id' => ['Ubicación incompatible.']]);
                }
                $location = $property->ubicacion_id ? Ubicacion::lockForUpdate()->findOrFail($property->ubicacion_id) : new Ubicacion;
                $location->fill($data['ubicacion']);
                if ($location->url_maps) {
                    $location->url_maps = str_replace('$', '', $location->url_maps);
                }
                $location->save();
                $data['ubicacion_id'] = $location->id;
            }
            $property->fill(Arr::except($data, ['propietario_ids', 'caracteristicas', 'ubicacion']));
            $property->save();
            if (! $id) {
                $property->codigo = Propiedad::siguienteCodigo();
                $property->save();
            }
            $property->propietarios()->sync($data['propietario_ids']);
            if (isset($data['caracteristicas'])) {
                $property->caracteristicas()->sync($data['caracteristicas']);
            }
            $row = $property->fresh()->load(['propietarios', 'sectorUrbano.distrito.ciudad', 'ubicacion', 'caracteristicas', 'imagenes'])->toArray();
            $row = PropertyVersion::annotate($row);

            return response()->json(['message' => $id ? 'Propiedad actualizada' : 'Propiedad registrada correctamente', 'data' => $row], $id ? 200 : 201)
                ->header('ETag', '"'.$row['version'].'"');
        });
    }

    private function rules(?int $id): array
    {
        $rules = ['propietario_ids' => 'required|array|min:1', 'propietario_ids.*' => 'integer|distinct|exists:propietarios,id',
            'sector_urbano_id' => 'required|exists:sectores_urbanos,id', 'ubicacion_id' => 'nullable|exists:ubicaciones,id|unique:propiedades,ubicacion_id'.($id ? ','.$id : ''),
            'tipo' => 'required|string|max:100', 'precio_venta' => 'required|numeric|min:0|decimal:0,2', 'moneda' => 'required|in:USD,BOB', 'superficie_m2' => 'required|numeric|min:0|decimal:0,2',
            'superficie_construida_m2' => 'nullable|numeric|min:0|decimal:0,2', 'frente_mts' => 'nullable|numeric|min:0|decimal:0,2', 'fondo_mts' => 'nullable|numeric|min:0|decimal:0,2',
            'habitaciones' => 'nullable|integer|min:0', 'banos' => 'nullable|integer|min:0', 'es_esquina' => 'nullable|boolean', 'direccion' => 'nullable|string|max:255',
            'nro_lote' => 'nullable|string|max:50', 'estado' => 'nullable|string|max:50', 'activo' => 'nullable|boolean', 'caracteristicas' => 'nullable|array',
            'caracteristicas.*' => 'integer|distinct|exists:caracteristicas,id', 'ubicacion' => 'sometimes|array', 'ubicacion.referencia' => 'nullable|string|max:255',
            'ubicacion.url_maps' => 'nullable|url', 'ubicacion.latitud' => 'nullable|numeric|between:-90,90', 'ubicacion.longitud' => 'nullable|numeric|between:-180,180'];
        foreach (['norte', 'sur', 'este', 'oeste'] as $side) {
            $rules['colinda_'.$side] = 'nullable|string|max:255';
        }
        if ($id) {
            $rules['codigo'] = 'nullable|string|max:100|unique:propiedades,codigo,'.$id;
        }

        return $rules;
    }
}

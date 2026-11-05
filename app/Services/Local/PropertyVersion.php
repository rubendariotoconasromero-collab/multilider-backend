<?php

namespace App\Services\Local;

use App\Models\Propiedad;

final class PropertyVersion
{
    public static function fromArray(array $row): string
    {
        $fields = array_intersect_key($row, array_flip(['id', 'sector_urbano_id', 'ubicacion_id', 'codigo', 'tipo', 'precio_venta', 'moneda',
            'superficie_m2', 'superficie_construida_m2', 'frente_mts', 'fondo_mts', 'habitaciones', 'banos', 'es_esquina', 'direccion', 'nro_lote',
            'colinda_norte', 'colinda_sur', 'colinda_este', 'colinda_oeste', 'estado', 'activo', 'es_destacado', 'created_at', 'updated_at']));
        foreach ($fields as $key => $value) {
            if ($value !== null) {
                $fields[$key] = (string) $value;
            }
        }
        foreach (['propietarios', 'caracteristicas'] as $name) {
            $ids = array_column($row[$name] ?? [], 'id');
            sort($ids);
            $fields[$name] = $ids;
        }
        $images = array_map(fn ($image) => [$image['id'], $image['url'], (int) $image['es_principal'], (int) ($image['publicada'] ?? true)], $row['imagenes'] ?? []);
        usort($images, fn ($a, $b) => $a[0] <=> $b[0]);
        $fields['imagenes'] = $images;
        $fields['ubicacion'] = $row['ubicacion'] ?? null;
        ksort($fields);

        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function model(Propiedad $property): string
    {
        return self::fromArray($property->load(['propietarios', 'caracteristicas', 'imagenes', 'ubicacion'])->toArray());
    }

    public static function annotate(array $row): array
    {
        $row['version'] = self::fromArray($row);
        foreach ($row['imagenes'] ?? [] as $key => $image) {
            if (isset($image['public_id']) && (! (bool) ($row['activo'] ?? false) || ! (bool) ($image['publicada'] ?? true))) {
                $row['imagenes'][$key]['url'] = '';
                $row['imagenes'][$key]['preview_url'] = '/api/catalogo/imagenes/'.$image['id'].'/contenido';
            }
        }
        $row['capabilities'] = ['can_edit_estado' => true];

        return $row;
    }
}

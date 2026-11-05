<?php

namespace App\Services\Remote;

final class PropertyResponseAdapter
{
    public static function fromRemote(array $row, bool $public = false): array
    {
        if (! isset($row['id'], $row['tipo'], $row['precio_venta'], $row['moneda'], $row['estado'], $row['superficie_m2'])
            || ! is_int($row['id']) || $row['id'] < 1 || ! is_string($row['tipo']) || ! is_string($row['estado'])
            || ! in_array($row['moneda'], ['BOB', 'USD'], true)) {
            throw new IntegrationException('Propiedad remota inválida.', 503, 'INVALID_SERVICE_RESPONSE');
        }
        // Eloquent MySQL serializes DECIMAL as strings and uncast booleans as 0/1.
        foreach (['precio_venta', 'superficie_m2', 'superficie_construida_m2', 'frente_mts', 'fondo_mts'] as $key) {
            if (isset($row[$key])) {
                if (! is_numeric($row[$key]) || ! is_finite((float) $row[$key])) {
                    throw new IntegrationException('Importe remoto inválido.', 503, 'INVALID_SERVICE_RESPONSE');
                }
                $exact = $row['decimal_values'][$key] ?? null;
                if (is_string($exact) && preg_match('/^-?[0-9]+\.[0-9]{2}$/D', $exact)) {
                    $row[$key] = $exact;
                } elseif (isset($row['version'])) {
                    throw new IntegrationException('Falta representación decimal exacta.', 503, 'INVALID_SERVICE_RESPONSE');
                } else {
                    $row[$key] = number_format((float) $row[$key], 2, '.', '');
                }
            }
        }
        foreach (($public ? ['caracteristicas', 'imagenes'] : ['propietarios', 'caracteristicas', 'imagenes']) as $key) {
            if (! isset($row[$key]) || ! is_array($row[$key]) || ! array_is_list($row[$key])) {
                throw new IntegrationException('Relación remota inválida.', 503, 'INVALID_SERVICE_RESPONSE');
            }
        }
        foreach (['es_esquina', 'activo', 'es_destacado'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        foreach ($row['imagenes'] as &$image) {
            if (isset($image['url'])) {
                $image['url'] = RemoteCatalogAdministrationGateway::imageUrl($image['url']);
            }
        }
        unset($image, $row['decimal_values']);

        if ($public) {
            $row = array_intersect_key($row, array_flip(['id', 'codigo', 'tipo', 'precio_venta', 'moneda', 'superficie_m2', 'superficie_construida_m2', 'frente_mts', 'fondo_mts', 'habitaciones', 'banos', 'es_esquina', 'direccion', 'nro_lote', 'colinda_norte', 'colinda_sur', 'colinda_este', 'colinda_oeste', 'estado', 'activo', 'es_destacado', 'sector_urbano_id', 'ubicacion_id', 'sector_urbano', 'ubicacion', 'caracteristicas', 'imagenes']));
        }

        // .NET DTO intentionally omits timestamps and ORM pivot objects; do not invent them.
        return $row;
    }
}

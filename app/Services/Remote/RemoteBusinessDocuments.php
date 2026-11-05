<?php

namespace App\Services\Remote;

use App\Http\Controllers\Api\ContratoController;
use App\Services\PdfFactory;

final class RemoteBusinessDocuments
{
    public function contract(array $packet)
    {
        $snapshot = json_decode(json_encode($packet['snapshot']), false);
        $contract = json_decode(json_encode($packet['contrato']), false);
        $sale = json_decode(json_encode($packet['venta']), false);
        $sale->cliente = $snapshot->cliente;
        $sale->propiedad = $snapshot->propiedad;
        $sale->propiedad->propietarios = collect($snapshot->propiedad->propietarios ?? []);
        $sale->propiedad->sectorUrbano = $snapshot->propiedad->sector_urbano ?? null;
        if ($sale->propiedad->sectorUrbano) {
            $sale->propiedad->sectorUrbano->distrito = $snapshot->propiedad->sector_urbano->distrito ?? null;
        }
        $contract->notaVenta = $sale;
        return app(ContratoController::class)->renderContract($contract);
    }
    public function receipt(array $packet)
    {
        $sale = $this->sale($packet['nota_venta'] ?? []);
        if (($packet['categoria'] ?? null) === 'COMISION_ASESOR') {
            $egreso = $this->model(\App\Models\Egreso::class, $packet);
            $egreso->setRelation('notaVenta', $sale);
            $egreso->setRelation('asesor', $sale->getRelation('asesor'));
            $egreso->setRelation('cuentaBancaria', isset($packet['cuenta_bancaria']) ? $this->model(\App\Models\CuentaBancaria::class, $packet['cuenta_bancaria']) : null);
            $egreso->setRelation('registradoPor', null);
            return app(\App\Http\Controllers\Api\ComisionAsesorController::class)->renderReceipt($egreso);
        }
        $pago = $this->model(\App\Models\Pago::class, $packet);
        $pago->setRelation('notaVenta', $sale);
        $pago->setRelation('metodoPago', isset($packet['metodo_pago']) ? $this->model(\App\Models\MetodoPago::class, $packet['metodo_pago']) : null);
        $pago->setRelation('cuenta', isset($packet['cuenta']) ? $this->model(\App\Models\CuentaBancaria::class, $packet['cuenta']) : null);
        return app(\App\Http\Controllers\Api\PagoController::class)->renderReceipt($pago);
    }
    private function model(string $class, array $data)
    {
        // Read-only view models from an authoritative DTO; none are saved or resolved by local ID.
        $model = new $class();
        $model->setRawAttributes(array_filter($data, fn ($value) => ! is_array($value)));
        return $model;
    }
    private function sale(array $packet)
    {
        $sale = $this->model(\App\Models\NotaVenta::class, $packet);
        $sale->setRelation('cliente', $this->model(\App\Models\Cliente::class, $packet['cliente'] ?? []));
        $sale->setRelation('asesor', $this->model(\App\Models\Asesor::class, $packet['asesor'] ?? []));
        $data = $packet['propiedad'] ?? [];
        $property = $this->model(\App\Models\Propiedad::class, $data);
        $geo = $data['sector_urbano'] ?? [];
        $sector = $this->model(\App\Models\SectorUrbano::class, $geo);
        $district = $this->model(\App\Models\Distrito::class, $geo['distrito'] ?? []);
        $district->setRelation('ciudad', $this->model(\App\Models\Ciudad::class, $geo['distrito']['ciudad'] ?? []));
        $sector->setRelation('distrito', $district);
        $property->setRelation('sectorUrbano', $sector);
        $sale->setRelation('propiedad', $property);
        return $sale;
    }
}

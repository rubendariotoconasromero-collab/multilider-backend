<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotaVenta;
use App\Models\Propiedad;
use App\Models\Asesor;
use App\Models\PlanPago;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\Contrato;
use App\Models\Egreso;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class NotaVentaController extends Controller
{
    // 1. Listar todas las ventas
    // 1. Listar ventas (CON FILTROS AVANZADOS)
    public function index(Request $request) {
        $query = NotaVenta::with(['asesor', 'cliente', 'propiedad.propietarios', 'propiedad.sectorUrbano.distrito.ciudad', 'pagos.metodoPago']);

        // Filtro por Fechas
        if ($request->filled('fecha_inicio') && $request->filled('fecha_fin')) {
            $query->whereBetween('fecha', [$request->fecha_inicio, $request->fecha_fin]);
        }
        // Filtro por Cliente
        if ($request->filled('cliente_id')) {
            $query->where('cliente_id', $request->cliente_id);
        }
        // Filtro por Asesor
        if ($request->filled('asesor_id')) {
            $query->where('asesor_id', $request->asesor_id);
        }
        // Filtro por Tipo de Venta
        if ($request->filled('tipo_venta') && $request->tipo_venta !== 'TODOS') {
            $query->where('tipo_venta', $request->tipo_venta);
        }

        $perPage = $request->input('per_page', 10);
        $ventas = $query->orderBy('fecha', 'desc')->orderBy('id', 'desc')->paginate($perPage);

        return response()->json($ventas, 200);
    }

    // 2. Registrar Nueva Venta
    public function store(Request $request) {
        $validatedData = $request->validate([
            'asesor_id'      => 'required|exists:asesores,id',
            'cliente_id'     => 'required|exists:clientes,id',
            'propiedad_id'   => 'required|exists:propiedades,id',
            'fecha'          => 'required|date',
            'monto_total'    => 'required|numeric|min:0',
            'monto_comision' => 'nullable|numeric|min:0',
            'tipo_venta'     => 'required|in:CONTADO,CREDITO',

            // Contado
            'descuento'      => 'nullable|numeric|min:0',
            'monto_liquido'  => 'required_if:tipo_venta,CONTADO|nullable|numeric|min:0',

            // Crédito (Agregamos validaciones para el plan de pagos)
            'cuota_inicial'  => 'required_if:tipo_venta,CREDITO|nullable|numeric|min:0',
            'saldo_credito'  => 'required_if:tipo_venta,CREDITO|nullable|numeric|min:0',
            'numero_cuotas'  => 'required_if:tipo_venta,CREDITO|nullable|integer|min:1',
            'tasa_interes'   => 'required_if:tipo_venta,CREDITO|nullable|numeric|min:0',
            'tasa_mora'        => 'nullable|numeric|min:0|max:100',
            'fecha_inicio_pago'=> 'required_if:tipo_venta,CREDITO|nullable|date',
            'metodo_pago_id'   => 'nullable|exists:metodos_pago,id',
            'cuenta_id'        => 'nullable|exists:cuentas_bancarias,id',
            'fecha_pago'       => 'nullable|date',
        ]);

        try {
            DB::beginTransaction();

            $propiedad = Propiedad::findOrFail($validatedData['propiedad_id']);
            if ($propiedad->estado === 'Vendido') {
                return response()->json(['message' => 'Esta propiedad ya fue vendida.'], 400);
            }

            // Calcular comisión desde el porcentaje registrado del asesor
            $asesor = Asesor::findOrFail($validatedData['asesor_id']);
            $montoComision = round($validatedData['monto_total'] * ($asesor->porcentaje_comision / 100), 2);

            // 1. Creamos la Nota de Venta
            $ventaData = collect($validatedData)->except(['numero_cuotas', 'tasa_interes', 'tasa_mora', 'fecha_inicio_pago', 'cuenta_id', 'fecha_pago'])->toArray();
            $ventaData['monto_comision'] = $montoComision;
            $venta = NotaVenta::create($ventaData);

            $propiedad->update(['estado' => 'Vendido']);
            $this->pushEstadoAMicroservicio($propiedad, 'Vendido');

            // 2. REGISTRAR PAGO - siempre como PENDIENTE_PAGO
            $metodoPagoId = $validatedData['metodo_pago_id'] ?? null;

            if ($venta->tipo_venta === 'CONTADO') {
                $concepto  = 'VENTA_CONTADO';
                $montoPago = $validatedData['monto_liquido'];
            } else {
                $concepto  = 'CUOTA_INICIAL';
                $montoPago = $validatedData['cuota_inicial'];
            }

            Pago::create([
                'nota_venta_id'  => $venta->id,
                'cuota_id'       => null,
                'metodo_pago_id' => $metodoPagoId,
                'cuenta_id'      => null,
                'concepto_pago'  => $concepto,
                'fecha_pago'     => null,
                'monto'          => $montoPago,
                'estado'         => 'PENDIENTE_PAGO',
                'observaciones'  => $venta->tipo_venta === 'CONTADO'
                    ? 'Pago contado pendiente de confirmar'
                    : 'Cuota inicial pendiente de pago',
            ]);

            // 3. CREAR CONTRATO AUTOMÁTICAMENTE EN ESTADO PENDIENTE
            $codigoContrato = 'CONT-' . date('Y') . '-' . str_pad($venta->id, 4, '0', STR_PAD_LEFT);
            Contrato::create([
                'nota_venta_id'   => $venta->id,
                'codigo_contrato' => $codigoContrato,
                'fecha_emision'   => $venta->fecha,
                'fecha_firma'     => null,
                'tipo_venta'      => $venta->tipo_venta === 'CONTADO' ? 'Contado' : 'Crédito',
                'url_doc'         => null,
                'estado'          => 'Pendiente',
            ]);

            // 4. SI ES CRÉDITO, GENERAMOS EL PLAN DE PAGOS AUTOMÁTICAMENTE
            if ($venta->tipo_venta === 'CREDITO') {
                $this->generarPlanDePagos(
                    $venta->id,
                    $validatedData['saldo_credito'],
                    $validatedData['numero_cuotas'],
                    $validatedData['tasa_interes'],
                    $validatedData['fecha_inicio_pago'],
                    $validatedData['tasa_mora'] ?? 0
                );
            }

            // 5. EGRESO AUTOMÁTICO DE COMISIÓN (estado PENDIENTE hasta que se desembolse)
            if ($montoComision > 0) {
                $monedaMap = ['BOB' => 'Bs', 'USD' => '$'];
                $moneda = $monedaMap[$propiedad->moneda] ?? 'Bs';
                Egreso::create([
                    'fecha'         => $venta->fecha,
                    'concepto'      => 'Comisión de venta - ' . $asesor->nombre_completo . ' (Venta #' . $venta->id . ')',
                    'categoria'     => 'COMISION_ASESOR',
                    'monto'         => $montoComision,
                    'moneda'        => $moneda,
                    'origen'        => 'AUTOMATICO',
                    'nota_venta_id' => $venta->id,
                    'asesor_id'     => $asesor->id,
                    'beneficiario'  => $asesor->nombre_completo,
                    'estado'        => 'PENDIENTE',
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'Venta registrada con éxito', 'data' => $venta->load('planPago.cuotas', 'pagos')], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al registrar la venta: ' . $e->getMessage()], 500);
        }
    }

    // 3. Ver detalle de una venta
    public function show($id) {
        $venta = NotaVenta::with([
            'asesor',
            'cliente',
            'propiedad.propietarios',
            'propiedad.sectorUrbano.distrito.ciudad',
            'planPago.cuotas',
            'pagos.metodoPago'
        ])->findOrFail($id);

        return response()->json($venta, 200);
    }

    // 4. Anular Venta (No se borra físicamente, se cambia estado)
    public function anular($id) {
        try {
            DB::beginTransaction();

            $venta = NotaVenta::findOrFail($id);
            
            if ($venta->estado === 'Anulada') {
                return response()->json(['message' => 'La venta ya se encuentra anulada'], 400);
            }

            // 1. Anulamos la venta
            $venta->update(['estado' => 'Anulada']);

            // 2. Liberamos la propiedad
            $propiedad = Propiedad::findOrFail($venta->propiedad_id);
            $propiedad->update(['estado' => 'Disponible']);
            $this->pushEstadoAMicroservicio($propiedad, 'Disponible');

            // 3. Anulamos el egreso de comisión asociado si está pendiente
            Egreso::where('nota_venta_id', $venta->id)
                ->where('categoria', 'COMISION_ASESOR')
                ->where('estado', 'PENDIENTE')
                ->update(['estado' => 'ANULADO']);

            DB::commit();
            return response()->json(['message' => 'Venta anulada y propiedad liberada correctamente'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al anular la venta: ' . $e->getMessage()], 500);
        }
    }

    private function generarPlanDePagos($ventaId, $capitalPrestado, $meses, $tasaAnual, $fechaInicio, $tasaMora = 0) {

        $fechaFinal = Carbon::parse($fechaInicio)->addMonths($meses - 1); // -1 porque la primera cuota es el mes 1

        // 1. Crear la cabecera del Plan
        $plan = PlanPago::create([
            'nota_venta_id' => $ventaId,
            'monto'         => $capitalPrestado,
            'numero_cuotas' => $meses,
            'fecha_inicio'  => $fechaInicio,
            'fecha_final'   => $fechaFinal,
            'plazo'         => $meses . ' Meses',
            'tasa_interes'  => $tasaAnual,
            'tasa_mora'     => $tasaMora,
        ]);

        // 2. Cálculos Matemáticos
        $tasaMensual = ($tasaAnual / 100) / 12;
        $saldoSobrante = $capitalPrestado;
        $fechaVencimiento = Carbon::parse($fechaInicio);

        // Si la tasa es > 0 aplicamos fórmula francesa, si es 0 es división simple
        if ($tasaMensual > 0) {
            $cuotaFija = $capitalPrestado * ($tasaMensual * pow(1 + $tasaMensual, $meses)) / (pow(1 + $tasaMensual, $meses) - 1);
        } else {
            $cuotaFija = $capitalPrestado / $meses;
        }

        // 3. Generar e insertar las cuotas mes a mes
        $cuotasInsert = [];
        for ($i = 1; $i <= $meses; $i++) {
            
            $interesMes = $saldoSobrante * $tasaMensual;
            $capitalMes = $cuotaFija - $interesMes;
            
            // Ajuste por redondeo en la última cuota
            if ($i == $meses) {
                $capitalMes = $saldoSobrante;
                $cuotaFija = $capitalMes + $interesMes;
            }

            $saldoSobrante -= $capitalMes;

            $cuotasInsert[] = [
                'plan_pago_id'      => $plan->id,
                'numero_cuota'      => $i,
                'fecha_vencimiento' => $fechaVencimiento->format('Y-m-d'),
                'monto_cuota'       => round($cuotaFija, 2),
                'monto_interes'     => round($interesMes, 2),
                'monto_capital'     => round($capitalMes, 2),
                'saldo_capital'     => round(max(0, $saldoSobrante), 2),
                'estado'            => 'Pendiente',
                'created_at'        => now(),
                'updated_at'        => now()
            ];

            // Sumamos un mes para la siguiente cuota
            $fechaVencimiento->addMonth(); 
        }

        // Inserción masiva para máxima eficiencia en la BD
        Cuota::insert($cuotasInsert);
    }

    /**
     * Refleja el nuevo estado de la propiedad en MSMultilider.Propiedades (best-effort).
     * Si el microservicio no responde, no debe interrumpir el flujo de venta/anulación:
     * el schedule de `propiedades:sync` corrige la copia local en el siguiente ciclo,
     * pero el catálogo del microservicio quedaría desactualizado hasta reintentar.
     */
    private function pushEstadoAMicroservicio(Propiedad $propiedad, string $estado): void
    {
        try {
            $token = Cache::remember('propiedades_sync_jwt', now()->addHours(7), function () {
                $authClient = new Client(['base_uri' => rtrim(config('services.seguridad.base_url'), '/') . '/']);
                $res = $authClient->post('api/auth/login', [
                    'json' => [
                        'correo' => config('services.seguridad.sync_email'),
                        'password' => config('services.seguridad.sync_password'),
                    ],
                ]);
                $body = json_decode($res->getBody()->getContents(), true);
                return $body['access_token'] ?? $body['accessToken'];
            });

            $client = new Client([
                'base_uri' => rtrim(config('services.propiedades.base_url'), '/') . '/',
                'timeout' => 5,
            ]);

            $res = $client->get("api/propiedades/{$propiedad->id}", [
                'headers' => ['Authorization' => "Bearer {$token}"],
            ]);
            $dto = json_decode($res->getBody()->getContents(), true);

            $client->put("api/propiedades/{$propiedad->id}", [
                'headers' => ['Authorization' => "Bearer {$token}"],
                'json' => [
                    'propietario_ids' => array_column($dto['propietarios'] ?? [], 'id'),
                    'sector_urbano_id' => $dto['sector_urbano_id'],
                    'ubicacion_id' => $dto['ubicacion_id'],
                    'tipo' => $dto['tipo'],
                    'codigo' => $dto['codigo'],
                    'precio_venta' => $dto['precio_venta'],
                    'moneda' => $dto['moneda'],
                    'superficie_m2' => $dto['superficie_m2'],
                    'superficie_construida_m2' => $dto['superficie_construida_m2'],
                    'frente_mts' => $dto['frente_mts'],
                    'fondo_mts' => $dto['fondo_mts'],
                    'habitaciones' => $dto['habitaciones'],
                    'banos' => $dto['banos'],
                    'es_esquina' => $dto['es_esquina'],
                    'direccion' => $dto['direccion'],
                    'nro_lote' => $dto['nro_lote'],
                    'colinda_norte' => $dto['colinda_norte'],
                    'colinda_sur' => $dto['colinda_sur'],
                    'colinda_este' => $dto['colinda_este'],
                    'colinda_oeste' => $dto['colinda_oeste'],
                    'estado' => $estado,
                    'activo' => $dto['activo'],
                    'caracteristicas' => array_column($dto['caracteristicas'] ?? [], 'id'),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning("No se pudo sincronizar estado de propiedad {$propiedad->id} hacia el microservicio: {$e->getMessage()}");
        }
    }
}

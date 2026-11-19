<?php

use App\Http\Controllers\Api\AsesorController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaracteristicaController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CiudadController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ComisionAsesorController;
use App\Http\Controllers\Api\ContratoController;
use App\Http\Controllers\Api\CorreoMasivoController;
use App\Http\Controllers\Api\CuentaBancariaController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DistritoController;
use App\Http\Controllers\Api\EgresoController;
use App\Http\Controllers\Api\EntregaController;
use App\Http\Controllers\Api\ImagenPropiedadController;
use App\Http\Controllers\Api\IngresoController;
use App\Http\Controllers\Api\LandingController;
use App\Http\Controllers\Api\MetodoPagoController;
use App\Http\Controllers\Api\MetodoPagoCuentaDefaultController;
use App\Http\Controllers\Api\NotaVentaController;
use App\Http\Controllers\Api\PagoController;
use App\Http\Controllers\Api\PagoPublicoController;
use App\Http\Controllers\Api\PermisoController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProfileMailController;
use App\Http\Controllers\Api\PropiedadController;
use App\Http\Controllers\Api\PropietarioController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\ReprogramacionController;
use App\Http\Controllers\Api\RolController;
use App\Http\Controllers\Api\RolPermisoController;
use App\Http\Controllers\Api\SectorUrbanoController;
use App\Http\Controllers\Api\UbicacionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\CatalogAdministration;
use App\Http\Middleware\IdentityAdministration;
use App\Http\Middleware\PublicCatalog;
use App\Services\MapsUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Rutas Públicas (Landing Page + Portal de Pagos)
Route::prefix('public')->group(function () {
    Route::post('clientes/buscar', [PagoPublicoController::class, 'buscarCliente']);
    Route::post('pagos/procesar', [PagoPublicoController::class, 'procesarPago']);
    Route::get('pagos/verificar/{id}', [PagoPublicoController::class, 'verificarEstado']);
    Route::post('pagos/callback', [PagoPublicoController::class, 'callbackLibelula']);
    Route::post('pagos/confirmar-retorno', [PagoPublicoController::class, 'confirmarRetorno']);
});

Route::get('/landing', [LandingController::class, 'getLandingData']);
Route::get('/landing/asesores/{id}/foto', [LandingController::class, 'adviserPhoto'])->whereNumber('id');
Route::get('/empresa/datos', [LandingController::class, 'getCompanyData']);
Route::get('/landing/propiedades', [LandingController::class, 'getPropiedades'])->middleware(PublicCatalog::class);
Route::get('/landing/propiedades/{id}', [LandingController::class, 'getPropiedad'])->middleware(PublicCatalog::class);
Route::get('/landing/propiedades/{id}/similares', [LandingController::class, 'getSimilares'])->middleware(PublicCatalog::class);
Route::get('/landing/ciudades', [LandingController::class, 'getCiudades'])->middleware(PublicCatalog::class);
Route::get('/landing/distritos', [LandingController::class, 'getDistritos'])->middleware(PublicCatalog::class);
Route::get('/landing/sectores-urbanos/{distritoId}', [LandingController::class, 'getSectoresUrbanos'])->middleware(PublicCatalog::class);

Route::post('/login', [AuthController::class, 'login']);

Route::post('/logout', [AuthController::class, 'logout']);

Route::middleware(['auth:architecture', \App\Http\Middleware\BusinessDispatch::class, \App\Http\Middleware\LocalBusinessCommands::class])->group(function () {
    Route::get('comercial/opciones', [\App\Http\Controllers\Api\CommercialOptionsController::class, 'index'])->middleware('permission:acceso_ventas');
    Route::get('operaciones', [\App\Http\Controllers\Api\OperationController::class, 'index']);
    Route::get('operaciones/{id}', [\App\Http\Controllers\Api\OperationController::class, 'show'])->whereUuid('id');
    Route::post('operaciones/{id}/reintentar', [\App\Http\Controllers\Api\OperationController::class, 'retry'])->whereUuid('id');

    // Utilidad: resolver URL acortada de Google Maps
    Route::get('/resolver-url-mapa', fn (Request $request, MapsUrlResolver $resolver) => $resolver->resolve($request));

    // Rutas de Autenticación
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/mi-cartera', [ClienteController::class, 'miCartera']);
    Route::get('/mi-cartera/pagos/{id}/comprobante', [ClienteController::class, 'miComprobante'])->whereNumber('id');

    // Dashboard
    Route::get('dashboard/admin', [DashboardController::class, 'admin']);
    Route::get('dashboard/asesor', [DashboardController::class, 'asesor']);

    // Reportes
    Route::prefix('reportes')->group(function () {
        Route::get('destinatarios', [ReporteController::class, 'destinatariosInforme']);
        Route::get('ventas-cobros', [ReporteController::class, 'ventasCobros']);
        Route::get('ventas-cobros/excel', [ReporteController::class, 'ventasCobrosExcel']);
        Route::get('ventas-cobros/pdf', [ReporteController::class, 'ventasCobrosPdf']);

        Route::get('cartera-mora', [ReporteController::class, 'carteraMora']);
        Route::get('cartera-mora/excel', [ReporteController::class, 'carteraMoraExcel']);
        Route::get('cartera-mora/pdf', [ReporteController::class, 'carteraMoraPdf']);

        Route::get('comisiones', [ReporteController::class, 'comisiones']);
        Route::get('comisiones/excel', [ReporteController::class, 'comisionesExcel']);
        Route::get('comisiones/pdf', [ReporteController::class, 'comisionesPdf']);

        Route::get('desempeno-asesores', [ReporteController::class, 'desempenoAsesores']);
        Route::get('desempeno-asesores/excel', [ReporteController::class, 'desempenoAsesoresExcel']);
        Route::get('desempeno-asesores/pdf', [ReporteController::class, 'desempenoAsesoresPdf']);

        Route::get('inventario-propiedades', [ReporteController::class, 'inventarioPropiedades']);
        Route::get('inventario-propiedades/excel', [ReporteController::class, 'inventarioPropiedadesExcel']);
        Route::get('inventario-propiedades/pdf', [ReporteController::class, 'inventarioPropiedadesPdf']);

        Route::post('enviar-informe', [ReporteController::class, 'enviarInforme']);
    });

    Route::get('catalogo/opciones-propiedad', [CatalogController::class, 'dispatch']);
    Route::get('catalogo/operaciones/{id}', [CatalogController::class, 'dispatch'])->whereUuid('id');
    Route::get('catalogo/imagenes/{id}/contenido', [CatalogController::class, 'image'])->whereNumber('id');

    // Perfil del usuario autenticado
    Route::get('/perfil', [ProfileController::class, 'show']);
    Route::put('/perfil', [ProfileController::class, 'update']);
    Route::put('/perfil/cambiar-password', [ProfileController::class, 'changePassword']);
    Route::prefix('perfil/correo')->middleware('throttle:60,1,profile-mail:')->group(function () {
        Route::get('estado', [ProfileMailController::class, 'status']);
        Route::post('conectar', [ProfileMailController::class, 'connect'])->middleware('throttle:5,1,profile-mail-connect:');
        Route::post('desconectar', [ProfileMailController::class, 'disconnect']);
        Route::get('mensajes', [ProfileMailController::class, 'messages']);
        Route::get('mensajes/{uid}', [ProfileMailController::class, 'read'])->whereNumber('uid');
        Route::get('mensajes/{uid}/adjuntos/{part}', [ProfileMailController::class, 'attachment'])->whereNumber('uid')->where('part', '[1-9][0-9]*(?:\.[1-9][0-9]*){0,15}');
        Route::post('mensajes/{uid}/mover', [ProfileMailController::class, 'move'])->whereNumber('uid');
        Route::post('enviar', [ProfileMailController::class, 'send'])->middleware('throttle:10,1,profile-mail-send:');
    });

    Route::prefix('correo/envios')->middleware('throttle:60,1,mail-archive:')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\MailArchiveController::class, 'index']);
        Route::get('{id}', [\App\Http\Controllers\Api\MailArchiveController::class, 'show'])->whereUuid('id');
        Route::get('{id}/archivos/{file}', [\App\Http\Controllers\Api\MailArchiveController::class, 'content'])->whereUuid('id')->whereUuid('file');
    });

    Route::middleware(IdentityAdministration::class)->group(function () {
        Route::apiResource('usuarios', UserController::class);
        Route::get('usuarios/{id}/asignaciones', [UserController::class, 'getAsignaciones']);
        Route::post('usuarios/{id}/asignaciones/sync', [UserController::class, 'syncAsignaciones']);

        Route::apiResource('roles', RolController::class);
        Route::get('roles/{id}/permisos', [RolController::class, 'getPermisos']);
        Route::post('roles/{id}/permisos/sync', [RolController::class, 'syncPermisos']);

        Route::apiResource('permisos', PermisoController::class);

        Route::apiResource('asignar-permisos', RolPermisoController::class);

    });

    Route::apiResource('propietarios', PropietarioController::class)->middleware(CatalogAdministration::class);
    Route::apiResource('ubicaciones', UbicacionController::class)->middleware(CatalogAdministration::class);
    Route::apiResource('propiedades', PropiedadController::class)->middleware(CatalogAdministration::class);
    Route::post('propiedades/{id}/caracteristicas/sync', [PropiedadController::class, 'syncCaracteristicas'])->middleware(CatalogAdministration::class);

    // Gestión de Imágenes de Propiedades
    Route::post('propiedades/{id}/imagenes', [ImagenPropiedadController::class, 'store'])->middleware(CatalogAdministration::class);
    Route::delete('imagenes-propiedades/{id}', [ImagenPropiedadController::class, 'destroy'])->middleware(CatalogAdministration::class);
    Route::patch('imagenes-propiedades/{id}/principal', [ImagenPropiedadController::class, 'setPrincipal'])->middleware(CatalogAdministration::class);

    Route::apiResource('asesores', AsesorController::class);
    Route::get('asesores/{id}/foto', [\App\Http\Controllers\Api\BusinessAssetController::class, 'photo'])->middleware('permission:acceso_asesores');
    Route::apiResource('clientes', ClienteController::class);
    Route::apiResource('ciudades', CiudadController::class)->middleware(CatalogAdministration::class);
    Route::apiResource('distritos', DistritoController::class)->middleware(CatalogAdministration::class);
    Route::get('sectores-urbanos/por-distrito/{distritoId}', [SectorUrbanoController::class, 'porDistrito'])->middleware(CatalogAdministration::class);
    Route::apiResource('sectores-urbanos', SectorUrbanoController::class)->middleware(CatalogAdministration::class);
    Route::apiResource('caracteristicas', CaracteristicaController::class)->middleware(CatalogAdministration::class);
    Route::apiResource('metodos-pago', MetodoPagoController::class);
    Route::apiResource('cuentas-bancarias', CuentaBancariaController::class);
    Route::apiResource('mapeo-metodos-cuentas', MetodoPagoCuentaDefaultController::class);
    Route::get('mapeo-metodos-cuentas/obtener-cuenta/{metodoId}', [MetodoPagoCuentaDefaultController::class, 'obtenerCuentaPorMetodo']);

    // Configuración de Empresa
    Route::post('empresa', [LandingController::class, 'updateEmpresa'])->middleware('permission:acceso_empresa');

    Route::apiResource('ventas', NotaVentaController::class)->except(['destroy', 'update']);
    Route::put('ventas/{id}/anular', [NotaVentaController::class, 'anular']);

    // Planes de Pago: amortización y reprogramaciones
    Route::get('ventas/{ventaId}/plan-pago', [ReprogramacionController::class, 'planPorVenta']);
    Route::post('planes-pago/{planId}/reprogramar', [ReprogramacionController::class, 'reprogramar']);
    Route::post('planes-pago/{planId}/amortizar', [ReprogramacionController::class, 'amortizar']);
    Route::get('planes-pago/{planId}/reprogramaciones', [ReprogramacionController::class, 'historial']);

    // Módulo de Contratos
    Route::middleware('permission:acceso_contratos')->group(function () {
        Route::get('contratos', [ContratoController::class, 'index']);
        Route::post('contratos/{id}/gestionar', [ContratoController::class, 'gestionar']);
        Route::put('contratos/{id}/anular', [ContratoController::class, 'anular']);
        Route::get('contratos/{id}/descargar', [ContratoController::class, 'descargar']);
        Route::get('contratos/{id}/generar-pdf', [ContratoController::class, 'generarPdf']);
    });

    // Módulo de Entregas
    Route::middleware('permission:acceso_entregas')->group(function () {
        Route::get('entregas', [EntregaController::class, 'index']);
        Route::put('entregas/{id}', [EntregaController::class, 'update']);
        Route::post('entregas/{id}/subir-acta', [EntregaController::class, 'subirActa']);
        Route::get('entregas/{id}/descargar-acta', [EntregaController::class, 'descargarActa']);
    });

    // Gestión de Pagos
    Route::middleware('permission:acceso_pagos')->group(function () {
        Route::post('pagos/bulk', [PagoController::class, 'storeBulk']);
        Route::apiResource('pagos', PagoController::class);
        Route::get('pagos/venta/{notaVentaId}/resumen', [PagoController::class, 'pagosPorVenta']);
        Route::get('pagos/cliente/{clienteId}/resumen', [PagoController::class, 'resumenPorCliente']);
        Route::put('pagos/{id}/cancelar', [PagoController::class, 'cancelar']);
        // Route::post('pagos/reportes/periodo', [PagoController::class, 'reportePeriodo']);
        Route::get('pagos/pendientes/listar', [PagoController::class, 'pagosPendientes']);
        Route::post('pagos/{id}/procesar', [PagoController::class, 'procesarPagoPendiente']);
        Route::get('pagos/{id}/comprobante', [PagoController::class, 'comprobante']);
    });

    // Ruta de reportes podría tener un permiso más específico si se desea,
    // pero por ahora lo dejamos bajo acceso_pagos o libre para admin
    Route::post('pagos/reportes/periodo', [PagoController::class, 'reportePeriodo'])->middleware('permission:acceso_reportes_pagos');

    // Módulo de Finanzas: Ingresos
    Route::middleware('permission:acceso_ingresos')->group(function () {
        Route::get('ingresos', [IngresoController::class, 'index']);
        Route::post('ingresos', [IngresoController::class, 'store']);
        Route::get('ingresos/resumen', [IngresoController::class, 'resumen']);
        Route::get('ingresos/{id}', [IngresoController::class, 'show']);
        Route::put('ingresos/{id}', [IngresoController::class, 'update']);
        Route::put('ingresos/{id}/anular', [IngresoController::class, 'anular']);
    });

    // Módulo de Finanzas: Egresos
    Route::middleware('permission:acceso_egresos')->group(function () {
        Route::get('egresos', [EgresoController::class, 'index']);
        Route::post('egresos', [EgresoController::class, 'store']);
        Route::get('egresos/resumen', [EgresoController::class, 'resumen']);
        Route::get('egresos/{id}', [EgresoController::class, 'show']);
        Route::put('egresos/{id}', [EgresoController::class, 'update']);
        Route::put('egresos/{id}/pagar', [EgresoController::class, 'pagar']);
        Route::put('egresos/{id}/anular', [EgresoController::class, 'anular']);

        // Módulo de Comisiones de Asesores
        Route::get('comisiones-asesores', [ComisionAsesorController::class, 'index']);
        Route::get('comisiones-asesores/{asesorId}/impagas', [ComisionAsesorController::class, 'impagas']);
        Route::get('comisiones-asesores/{asesorId}/pagadas', [ComisionAsesorController::class, 'pagadas']);
        Route::get('comisiones-asesores/egreso/{egresoId}/comprobante', [ComisionAsesorController::class, 'comprobante']);
    });

    // Módulo de Correo Masivo
    Route::prefix('correo-masivo')->middleware('permission:acceso_correo_masivo')->group(function () {
        Route::get('grupos', [CorreoMasivoController::class, 'grupos']);
        Route::get('destinatarios', [CorreoMasivoController::class, 'destinatarios']);
        Route::post('preview-destinatarios', [CorreoMasivoController::class, 'previewDestinatarios']);
        Route::post('enviar', [CorreoMasivoController::class, 'enviar']);
        Route::get('historial', [CorreoMasivoController::class, 'historial']);
        Route::get('estado/{id}', [CorreoMasivoController::class, 'estado'])->whereNumber('id');
    });

});

<?php

use GuzzleHttp\Handler\CurlMultiHandler;

define('F5_ACCEPTANCE_HELPERS_ONLY', true);
require __DIR__.'/PhaseFiveAcceptance.php';

try {
    $transport = new CurlMultiHandler(['select_timeout' => 0.1]);
    $client = endpointF5('PHASE5_API_LOCAL');
    $token = loginF5();
    [$status] = callF5('GET', 'comercial/opciones');
    checkF5($status === 200, 'Laravel local failed during certificate rotation.');

    $client = endpointF5('PHASE5_API');
    $token = loginF5();
    $profile = operationF5();
    $suffix = substr($profile['operacion_id'], 0, 8);
    [$status] = callF5('POST', 'clientes', $profile + ['ci' => 'ROT-'.$suffix, 'lugar_expedicion' => 'LP',
        'nombre_completo' => 'Identidad de rotación', 'correo' => 'rotation-'.$suffix.'@example.test', 'estado' => true]);
    checkF5(in_array($status, [200, 202], true) && waitF5($profile['operacion_id'])['state'] === 'Completada',
        'The Seguridad client identity could not read and activate the profile through Comercial.');
    $sale = saleF5();
    $id = completedSaleF5($sale);
    [$status, $payments] = callF5('GET', "pagos?nota_venta_id=$id");
    checkF5($status === 200 && count($payments['data']) === 1
        && $payments['data'][0]['estado'] === 'PENDIENTE_PAGO'
        && propertyStateF5($sale['propiedad_id']) === 'Vendido', 'Sale did not have one pending obligation after rotation.');
    echo $sale['operacion_id'].PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'F5 rotation: '.$error->getMessage().PHP_EOL);
    exit(1);
}

<?php

use GuzzleHttp\Handler\CurlMultiHandler;

define('F5_ACCEPTANCE_HELPERS_ONLY', true);
require __DIR__.'/PhaseFiveAcceptance.php';

function findDocumentF5(string $family, string $key, int $value): array
{
    [$status, $rows] = callF5('GET', "$family?per_page=100");
    checkF5($status === 200, "Document list unavailable: $family ($status).");
    foreach ($rows['data'] as $row) {
        if ((int) $row[$key] === $value) {
            return $row;
        }
    }
    throw new RuntimeException("Missing $family fixture.");
}

function downloadF5(string $route, string $filename): string
{
    global $client, $token;
    $response = $client->get('/api/'.$route, ['headers' => ['Authorization' => 'Bearer '.$token]]);
    $pdf = (string) $response->getBody();
    checkF5($response->getStatusCode() === 200 && str_starts_with($pdf, '%PDF-')
        && str_contains($response->getHeaderLine('Content-Disposition'), $filename), "Wrong PDF or filename for $route.");
    $anonymous = $client->get('/api/'.$route, ['headers' => ['Accept' => 'application/json']]);
    checkF5($anonymous->getStatusCode() === 401, "Anonymous download of $route.");

    return $pdf;
}

function attachF5($endpoint, int $contract, string $content, string $name, string $command): \Psr\Http\Message\ResponseInterface
{
    global $token;
    return $endpoint->post("/api/contratos/$contract/gestionar", [
        'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'Idempotency-Key' => $command],
        'multipart' => [
            ['name' => 'archivo', 'contents' => $content, 'filename' => $name, 'headers' => ['Content-Type' => 'application/pdf']],
            ['name' => 'command_id', 'contents' => $command],
            ['name' => 'firmado', 'contents' => '1'],
            ['name' => 'fecha_firma', 'contents' => '2026-11-04'],
        ],
    ]);
}

if (defined('F5_DOCUMENT_HELPERS_ONLY')) {
    return;
}

try {
    $transport = new CurlMultiHandler(['select_timeout' => 0.1]);
    $output = realpath(getenv('MULTILIDER_F5_BOUNDARY_DIR'));
    checkF5($output !== false && preg_match('/^f5-boundary-[a-f0-9]{32}$/D', basename($output)) === 1
        && dirname($output) === realpath(__DIR__.'/../../storage/framework/testing'), 'Unsafe document evidence path.');
    $manifest = [];
    $sign = ['firmado' => true, 'fecha_firma' => '2026-11-04', 'fecha_programada_entrega' => '2026-11-06'];
    foreach (['LOCAL' => 'PHASE5_API_LOCAL', 'REMOTO' => 'PHASE5_API'] as $mode => $endpoint) {
        $client = endpointF5($endpoint);
        $token = loginF5();
        foreach (['BOB' => 'CONTADO', 'USD' => 'CREDITO', 'USD-CONTADO' => 'CONTADO', 'BOB-CREDITO' => 'CREDITO'] as $tag => $type) {
            $currency = explode('-', $tag)[0];
            $sale = saleF5('12345.25', $currency);
            if ($type === 'CREDITO') {
                $sale = array_replace($sale, ['tipo_venta' => $type, 'cuota_inicial' => 100, 'saldo_credito' => 12245.25,
                    'numero_cuotas' => 2, 'tasa_interes' => 8, 'tasa_mora' => 2, 'fecha_inicio_pago' => '2027-01-31']);
            }
            [$status, $created] = callF5('POST', 'ventas', $sale);
            checkF5($status === ($mode === 'LOCAL' ? 201 : 202), "Could not create $mode $tag sale.");
            $id = $mode === 'LOCAL' ? $created['data']['id'] : waitF5($sale['operacion_id'])['venta_id'];
            $contract = findDocumentF5('contratos', 'nota_venta_id', $id);
            $contractId = $contract['id'];
            $code = $contract['codigo_contrato'];
            $route = "contratos/$contractId/generar-pdf";
            $name = "$mode-$tag";
            $pdf = downloadF5($route, $code.'_contrato.pdf');
            file_put_contents("$output/$name.pdf", $pdf);
            $manifest[$name] = ['code' => $code, 'type' => $type, 'currency' => $currency];
            if ($tag === 'USD-CONTADO') {
                [$status] = callF5('POST', "contratos/$contractId/gestionar", operationF5() + $sign);
                checkF5($status === 200, "Could not sign $mode delivery fixture.");
                $delivery = findDocumentF5('entregas', 'contrato_id', $contractId);
                [$status] = callF5('PUT', "entregas/{$delivery['id']}", operationF5() + [
                    'estado' => 'Entregado', 'fecha_entrega' => '2026-11-06', 'fecha_programada' => '2026-11-06',
                    'entregado_por' => 'Operador ficticio', 'recibido_por' => 'Cliente ficticio',
                ]);
                checkF5($status === 200 && ! paymentsF5($id), "Could not complete the unpaid $mode delivery.");
                [$status] = callF5('PUT', "ventas/$id/anular", operationF5());
                checkF5($status === 409 && propertyStateF5($sale['propiedad_id']) === 'Vendido' && ! paymentsF5($id),
                    "An unpaid delivered $mode sale was released or a refund was created.");
            }
            if ($tag !== 'BOB') {
                continue;
            }

            $ledger = operationF5() + ['nota_venta_id' => $id, 'monto' => 100, 'metodo_pago_id' => 1,
                'cuenta_id' => 1, 'fecha_pago' => '2026-11-04', 'concepto_pago' => 'VENTA_CONTADO'];
            [$status, $payment] = callF5('POST', 'pagos', $ledger);
            checkF5(in_array($status, [200, 201], true), "Fixture payment failed ($status).");
            $paymentId = $payment['data']['id'];
            file_put_contents("$output/$mode-recibo.pdf", downloadF5("pagos/$paymentId/comprobante", '.pdf'));

            $document = "%PDF-1.4\n% Original sintético F5\n%%EOF";
            $response = attachF5($client, $contractId, $document, 'contrato-prueba.pdf', uuidF5());
            checkF5($response->getStatusCode() === 200, "Could not attach initial $mode contract.");
            foreach ([
                ['<html>no es PDF</html>', '../traversal.pdf'],
                ["%PDF-1.4\n".str_repeat('X', 10 * 1024 * 1024)."\n%%EOF", 'exceso.pdf'],
            ] as [$contents, $filename]) {
                $response = attachF5($mode === 'LOCAL' ? $client : endpointF5('PHASE5_COMMERCIAL'), $contractId, $contents, $filename, uuidF5());
                checkF5(in_array($response->getStatusCode(), [413, 422], true), "Hostile file accepted ($mode).");
            }
            checkF5(downloadF5("contratos/$contractId/descargar", '.pdf') === $document, "Rejected file changed $mode attachment.");

            [$status] = callF5('POST', "contratos/$contractId/gestionar", operationF5() + $sign);
            checkF5($status === 200, "Could not schedule $mode delivery.");
            $delivery = findDocumentF5('entregas', 'contrato_id', $contractId);
            [$status] = callF5('PUT', "entregas/{$delivery['id']}", operationF5() + ['estado' => 'Entregado',
                'fecha_entrega' => '2026-11-06', 'fecha_programada' => '2026-11-06',
                'entregado_por' => 'Operador ficticio', 'recibido_por' => 'Cliente ficticio']);
            checkF5($status === 200, "Could not complete $mode delivery ($status).");
            [$status] = callF5('PUT', "ventas/$id/anular", operationF5());
            checkF5($status === 409, 'Delivered property could be cancelled.');
            [$status] = callF5('POST', "contratos/$contractId/gestionar", operationF5() + ['firmado' => false]);
            checkF5($status === 409, 'Delivered property could be unsigned.');
            checkF5(propertyStateF5($sale['propiedad_id']) === 'Vendido' && count(paymentsF5($id)) === 1,
                'Rejection modified delivered property or payment history.');
        }
    }

    $operation = saleF5();
    $marker = checkpointF5($operation['operacion_id'], 'PrepararFinanzas', 'before');
    [$status] = callF5('POST', 'ventas', $operation);
    checkF5($status === 202, 'Incomplete fixture was not admitted.');
    try {
        hitF5($marker);
        // Contracts of preparing sales must not appear in the operational list.
        [$status, $contracts] = callF5('GET', 'contratos?per_page=100');
        checkF5($status === 200 && ! array_filter($contracts['data'], fn ($c) =>
            ($c['nota_venta']['propiedad']['id'] ?? null) === $operation['propiedad_id']), 'Preparing contract appeared in the public operational list.');
        $connection = (string) getenv('MULTILIDER_COMERCIAL_TEST_CONNECTION');
        checkF5(preg_match('/^Server=localhost;Database=(multilider_comercial_f4_test_[a-f0-9]{32});/D', $connection, $target) === 1,
            'Unsafe commercial fixture database.');
        $dbName = $target[1];
        $db = new PDO("sqlsrv:Server=localhost;Database=$dbName;TrustServerCertificate=1");
        $id = (int) queryF5($db, 'SELECT c.Id FROM dbo.Contratos c JOIN dbo.Ventas v ON v.Id=c.NotaVentaId WHERE v.SolicitudId=?',
            [$operation['operacion_id']]);
        checkF5($id > 0, 'Preparing contract was not persisted.');
        [$status] = callF5('POST', "contratos/$id/gestionar", operationF5() + $sign);
        checkF5($status === 409, 'Preparing contract could be signed by ID.');
    } finally {
        file_put_contents($marker.'.release', '');
    }
    $completed = waitF5($operation['operacion_id']);
    checkF5($completed['state'] === 'Completada', 'Prepared document fixture could not complete.');
    $id = $completed['venta_id'];
    $contract = findDocumentF5('contratos', 'nota_venta_id', $id);
    $contractId = $contract['id'];
    $route = "contratos/$contractId/generar-pdf";
    $filename = $contract['codigo_contrato'].'_contrato.pdf';
    file_put_contents("$output/snapshot-before.pdf", downloadF5($route, $filename));
    [$status] = callF5('POST', 'empresa', ['nombre' => 'Empresa ficticia F5', 'color_primario' => '#246083']);
    checkF5($status === 200, 'Could not edit company data in Laravel.');
    [$status, $property] = callF5('GET', "propiedades/{$operation['propiedad_id']}");
    checkF5($status === 200, 'Could not read property to test snapshot.');
    $edit = $client->put("/api/propiedades/{$operation['propiedad_id']}", [
        'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json',
            'If-Match' => '"'.$property['version'].'"', 'Idempotency-Key' => uuidF5()],
        'json' => ['propietario_ids' => array_column($property['propietarios'], 'id'),
            'sector_urbano_id' => $property['sector_urbano_id'], 'tipo' => $property['tipo'],
            'moneda' => $property['moneda'], 'precio_venta' => $property['precio_venta'],
            'superficie_m2' => $property['superficie_m2'], 'activo' => true, 'direccion' => 'Dirección ficticia posterior'],
    ]);
    $status = $edit->getStatusCode();
    checkF5($status === 200, "Could not edit property to test snapshot ($status).");
    file_put_contents("$output/snapshot-after.pdf", downloadF5($route, $filename));
    $cancel = operationF5();
    [$status] = callF5('PUT', "ventas/$id/anular", $cancel);
    checkF5($status === 202 && waitF5($cancel['operacion_id'])['state'] === 'Completada', 'Could not cancel document fixture.');
    [$status] = callF5('POST', "contratos/$contractId/gestionar", operationF5() + $sign);
    checkF5($status === 409, 'Cancelled contract could be signed.');
    foreach ([['POST', 'ventas', $operation], ['PUT', "ventas/$id/anular", $cancel]] as [$method, $path, $body]) {
        [$status, $receipt] = callF5($method, $path, $body);
        checkF5($status === 202 && $receipt['state'] === 'Completada' && $receipt['venta_id'] === $id,
            'Completed command did not recover the original receipt.');
    }
    checkF5(propertyStateF5($operation['propiedad_id']) === 'Disponible' && ! paymentsF5($id), 'Replay reinstated effects of a cancelled sale.');
    [$status, $projection] = callF5('GET', "ventas/$id");
    checkF5($status === 200 && $projection['estado'] === 'Anulada', 'Replay changed the current sale projection.');
    file_put_contents("$output/documents.json", json_encode($manifest, JSON_THROW_ON_ERROR));
    echo "F5 document HTTP acceptance: $checks checks passed. Eight contract layouts, two receipts, snapshot, delivery and cancel replay.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}

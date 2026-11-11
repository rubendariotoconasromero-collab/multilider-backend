<?php

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\Utils;

define('F5_ACCEPTANCE_HELPERS_ONLY', true);
require __DIR__.'/PhaseFiveAcceptance.php';

function paymentFixtureF5(bool $local): array
{
    $sale = saleF5('1000.00');
    if ($local) {
        [$status, $created] = callF5('POST', 'ventas', $sale);
        checkF5($status === 201, 'Local test obligation could not be prepared.');
        $id = $created['data']['id'];
    } else {
        $id = completedSaleF5($sale);
    }
    [$status, $payments] = callF5('GET', "pagos?nota_venta_id=$id");
    $pending = array_values(array_filter($payments['data'] ?? [], fn ($row) => (int) $row['nota_venta_id'] === $id && $row['estado'] === 'PENDIENTE_PAGO'));
    checkF5($status === 200 && count($pending) === 1, 'Fixture must have one pending obligation.');

    return [$id, $pending[0]['id']];
}

function cashPaymentF5(string $command): array
{
    return ['operacion_id' => $command, 'command_id' => $command, 'solicitud_id' => $command,
        'metodo_pago_id' => 1, 'cuenta_id' => 1, 'fecha_pago' => '2026-11-04'];
}

function paymentEffectF5(int $sale, int $payment): void
{
    [$status, $confirmed] = callF5('GET', "pagos/$payment");
    $confirmed = $confirmed['data'] ?? $confirmed;
    checkF5($status === 200 && $confirmed['estado'] === 'PAGADO' && (float) $confirmed['monto'] === 1000.0,
        'Payment was not recorded exactly once.');
    [$status, $incomes] = callF5('GET', 'ingresos?per_page=100');
    $rows = array_values(array_filter($incomes['data'] ?? [], fn ($i) => (int) ($i['pago_id'] ?? 0) === $payment && (int) ($i['nota_venta_id'] ?? 0) === $sale));
    checkF5($status === 200 && count($rows) === 1 && (float) $rows[0]['monto'] === 1000.0, 'Missing or duplicated receipt in ledger.');
}

function ledgerRaceF5($primary, $secondary, int $sale, bool $local): void
{
    global $token;
    foreach (['ingresos', 'egresos'] as $family) {
        $base = ['fecha' => '2026-11-04', 'concepto' => 'Movimiento manual de prueba', 'categoria' => 'OTRO',
            'monto' => 25, 'moneda' => $local ? 'Bs' : 'BOB'];
        $made = asyncF5($primary, $token, 'POST', $family, operationF5() + $base)->wait();
        $result = json_decode((string) $made->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $id = ($result['data'] ?? $result)['id'] ?? null;
        checkF5(in_array($made->getStatusCode(), [200, 201], true) && $id > 0, "Could not create manual $family.");
        $form = operationF5() + array_replace($base, ['monto' => 26.50]);
        $form['solicitud_id'] = $form['command_id'];
        $path = "$family/$id";
        [$first, $second] = Utils::unwrap([
            asyncF5($primary, $token, 'PUT', $path, $form),
            asyncF5($secondary, $token, 'PUT', $path, $form),
        ]);
        checkF5($first->getStatusCode() === 200 && $second->getStatusCode() === 200
            && [$first->getHeaderLine('Idempotency-Replayed'), $second->getHeaderLine('Idempotency-Replayed')] !== ['', '']
            && json_decode((string) $first->getBody(), true) === json_decode((string) $second->getBody(), true),
            "Concurrent $family editing did not return one durable receipt.");
        $changed = asyncF5($secondary, $token, 'PUT', $path, array_replace($form, ['monto' => 999]))->wait();
        checkF5($changed->getStatusCode() === 409, "Changed payload reused the $family receipt.");
        [$status, $saved] = callF5('GET', $path);
        checkF5($status === 200 && (float) (($saved['data'] ?? $saved)['monto']) === 26.50, "Wrong final $family amount.");
        [$status, $list] = callF5('GET', "$family?per_page=100");
        $automatic = array_values(array_filter($list['data'] ?? [], fn ($row) =>
            (int) ($row['nota_venta_id'] ?? 0) === $sale && $row['origen'] === 'AUTOMATICO'));
        checkF5($status === 200 && count($automatic) === 1, "Missing automatic $family for fixture sale.");
        $denied = asyncF5($secondary, $token, 'PUT', "$family/{$automatic[0]['id']}",
            operationF5() + array_replace($base, ['monto' => 999]))->wait();
        checkF5($denied->getStatusCode() === 409, "Automatic $family update returned {$denied->getStatusCode()} in ".($local ? 'local' : 'remote').'.');
    }
}

try {
    $transport = new CurlMultiHandler(['select_timeout' => 0.1]);
    $client = endpointF5('PHASE5_API');
    $finance = endpointF5('PHASE5_FINANCE');
    $token = loginF5();
    if ($argv[1] === 'prepare') {
        [$sale, $payment] = paymentFixtureF5(false);
        $command = uuidF5();
        echo json_encode(['sale' => $sale, 'payment' => $payment, 'command' => $command,
            'marker' => checkpointF5($command, 'Cobro', 'after')], JSON_THROW_ON_ERROR);
        exit;
    }
    if (in_array($argv[1], ['write', 'verify'], true)) {
        $sale = (int) $argv[2];
        $payment = (int) $argv[3];
        $data = cashPaymentF5($argv[4]);
        if ($argv[1] === 'write') {
            try {
                asyncF5($finance, $token, 'POST', "pagos/$payment/procesar", $data)->wait();
            } catch (GuzzleHttp\Exception\TransferException) {
                exit;
            }
            throw new RuntimeException('Process cut did not lose the payment response.');
        }
        paymentEffectF5($sale, $payment);
        $replay = asyncF5($finance, $token, 'POST', "pagos/$payment/procesar", $data)->wait();
        checkF5($replay->getStatusCode() === 200 && $replay->getHeaderLine('Idempotency-Replayed') === 'true', 'Payment receipt was not replayed.');
        $changed = asyncF5($finance, $token, 'POST', "pagos/$payment/procesar", array_replace($data, ['fecha_pago' => '2026-11-05']))->wait();
        checkF5($changed->getStatusCode() === 409, 'Payment replay accepted a different payload.');
        paymentEffectF5($sale, $payment);
        echo "F5-F08: confirmed payment survived a process cut before its response and replayed a single income.\n";
        exit;
    }
    if ($argv[1] !== 'race') {
        throw new RuntimeException('Unknown payment exercise.');
    }
    foreach ([true, false] as $local) {
        $client = endpointF5($local ? 'PHASE5_API_LOCAL' : 'PHASE5_API');
        // Waiting on the same curl multi handler drains the paused callback too.
        $other = endpointF5($local ? 'PHASE5_API_LOCAL_SECOND' : 'PHASE5_FINANCE_SECOND', new CurlMultiHandler(['select_timeout' => 0.1]));
        $callback = $local ? $client : $finance;
        $token = loginF5();
        [$sale, $payment] = paymentFixtureF5($local);
        $id = uuidF5();
        $reference = 'TEMP-'.$id;
        [$status, $order] = callF5('POST', 'public/pagos/procesar', ['operacion_id' => $id, 'cliente_id' => 1,
            'pago_ids' => [$payment], 'cuota_ids' => [], 'ci_pagador' => 'PRUEBA', 'telefono_pagador' => '70000000',
            'nombres_pagador' => 'Cliente', 'apellidos_pagador' => 'Ficticio', 'correo_pagador' => 'buyer@example.test']);
        checkF5($status === 200 && $order['id_transaccion'] === $reference && $order['es_prueba'] === true,
            'Test order was not created by the fake provider ('.($local ? 'local' : 'remote')." $status).");
        $marker = checkpointF5($id, 'Callback', 'after');
        file_put_contents(dirname($marker).'/'.str_replace('-', '', $id).'-provider-paid', '');
        $notification = ['identificador' => $reference, 'transaction_id' => 'fixture', 'error' => '0'];
        $waiting = $callback->postAsync('/api/public/pagos/callback', ['json' => $notification]);
        try {
            eventuallyF5(function () use ($transport, $marker) {
                $transport->tick();
                return is_file($marker.'.hit');
            }, 'Callback did not reach the simulated verification boundary.');
            $manual = asyncF5($other, $token, 'POST', "pagos/$payment/procesar", cashPaymentF5(uuidF5()))->wait();
            checkF5($manual->getStatusCode() === 200, 'Concurrent manual payment did not complete during the callback.');
        } finally {
            file_put_contents($marker.'.release', '');
        }
        checkF5($waiting->wait()->getStatusCode() === 200, 'Concurrent callback was not accepted.');
        checkF5($callback->post('/api/public/pagos/callback', ['json' => $notification])->getStatusCode() === 200, 'Callback could not be replayed.');
        [$status, $order] = callF5('GET', "public/pagos/verificar/$reference");
        checkF5($status === 200 && $order['estado'] === 'PAGADO_PRUEBA' && $order['deuda_real_liquidada'] === false,
            'Test-provider receipt changed the real status.');
        paymentEffectF5($sale, $payment);
        ledgerRaceF5($callback, $other, $sale, $local);
    }
    echo "F5-P09: overlapping callback and manual confirmation in separate Laravel and Finanzas processes, one payment and income per mode.\n";
    echo "F5-F16: identical concurrent manual edits replayed one receipt; changed payload and automatic changes rejected in both stores.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}

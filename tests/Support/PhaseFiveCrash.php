<?php

use GuzzleHttp\Handler\CurlMultiHandler;

define('F5_ACCEPTANCE_HELPERS_ONLY', true);
require __DIR__.'/PhaseFiveAcceptance.php';

try {
    $transport = new CurlMultiHandler(['select_timeout' => 0.1]);
    $client = $remote = endpointF5('PHASE5_API');
    $local = endpointF5('PHASE5_API_LOCAL');
    $action = $argv[1] ?? '';
    if ($action === 'local') {
        $client = $local;
        $token = loginF5();
        [$status] = callF5('GET', 'comercial/opciones');
        checkF5($status === 200, 'Laravel local unavailable while fixture process is stopped.');
        exit(0);
    }
    $token = loginF5();
    $root = realpath(getenv('MULTILIDER_F5_BOUNDARY_DIR'));
    if (! $root || dirname($root) !== realpath(__DIR__.'/../../storage/framework/testing')
        || ! preg_match('/^f5-boundary-[a-f0-9]{32}$/D', basename($root))) {
        throw new RuntimeException('Invalid crash fixture root.');
    }
    $log = $root.DIRECTORY_SEPARATOR.'crash-cases.jsonl';
    if ($action === 'prepare') {
        [, , $kind, $point, $side] = $argv;
        checkF5(in_array($kind, ['sale', 'cancel', 'abort'], true)
            && preg_match('/^[A-Za-z-]+$/D', $point) && in_array($side, ['before', 'after'], true), 'Invalid crash case.');
        $sale = array_replace(saleF5(), ['tipo_venta' => 'CREDITO', 'monto_liquido' => null, 'cuota_inicial' => 1000,
            'numero_cuotas' => 3, 'tasa_interes' => 8, 'tasa_mora' => 3, 'fecha_inicio_pago' => '2026-12-31']);
        $saleId = $kind === 'cancel' ? completedSaleF5($sale) : null;
        $command = $kind === 'cancel' ? operationF5() : $sale;
        $id = $command['operacion_id'];
        $marker = checkpointF5($id, $point, $side);
        if ($kind === 'abort') {
            file_put_contents($root.DIRECTORY_SEPARATOR.str_replace('-', '', $id).'-PrepararFinanzas.reject', '');
        }
        [$status] = $kind === 'cancel' ? callF5('PUT', "ventas/$saleId/anular", $command) : callF5('POST', 'ventas', $command);
        checkF5($status === 202, "Crash fixture not admitted: $kind $point $side");
        // Preparing an abort can legitimately wait through the worker's
        // exponential backoff before reaching the first compensating step.
        $deadline = microtime(true) + ($kind === 'abort' ? 105 : 45);
        while (! is_file($marker.'.hit') && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (! is_file($marker.'.hit')) {
            [$code, $progress] = callF5('GET', 'operaciones/'.$id);
            throw new RuntimeException("Boundary $point:$side not reached. Progress $code "
                .($progress['step'] ?? '').'/'.($progress['state'] ?? '').'/'.($progress['error_code'] ?? ''));
        }
        $pid = (int) file_get_contents($marker.'.hit');
        checkF5($pid > 0, 'Invalid crashed fixture PID.');
        $case = compact('id', 'saleId', 'sale', 'kind', 'point', 'side', 'pid', 'command');
        if (($argv[5] ?? '') !== 'unlogged') {
            file_put_contents($log, json_encode($case, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
        }
        echo json_encode($case, JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    if ($action === 'await-state') {
        [, , $id, $wanted] = $argv;
        $progress = waitF5($id, 85);
        checkF5($progress['state'] === $wanted, "Expected $wanted but received {$progress['state']}.");
        exit(0);
    }
    if ($action === 'retry') {
        $id = $argv[2];
        [$status] = callF5('POST', "operaciones/$id/reintentar", operationF5());
        checkF5($status === 202 && waitF5($id, 45)['state'] === 'Completada', 'Audited operator retry did not finish.');
        exit(0);
    }
    if ($action === 'between') {
        $case = json_decode(array_slice(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -1)[0], true, 32, JSON_THROW_ON_ERROR);
        if (in_array($case['point'], ['Respuesta-CerrarFinanzas', 'Respuesta-RevertirPropiedad'], true)) {
            $saleId = $case['saleId'];
            $closed = $case['point'] === 'Respuesta-CerrarFinanzas';
            checkF5(propertyStateF5($case['sale']['propiedad_id']) === ($closed ? 'Vendido' : 'Disponible'),
                'Property changed before the protected reversal or failed to reverse.');
            $payment = array_replace(payF5($saleId), ['concepto_pago' => 'CUOTA_INICIAL', 'metodo_pago_id' => 1, 'cuenta_id' => 1]);
            [$status] = callF5('POST', 'pagos', $payment);
            checkF5($status === 409 && count(paymentsF5($saleId)) === 0, 'A late payment reopened the closed financing.');
        }
        exit(0);
    }
    checkF5($action === 'verify', 'Unknown crash fixture action.');
    $cases = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($cases as $line) {
        $case = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        $id = $case['id'];
        $kind = $case['kind'];
        $progress = waitF5($id, 85);
        $expectedState = $kind === 'abort' ? 'Compensada' : 'Completada';
        checkF5($progress['state'] === $expectedState && $progress['id'] === $id,
            "Wrong terminal state after $kind {$case['point']} {$case['side']}: ".($progress['error_code'] ?? $progress['state']));
        checkF5(propertyStateF5($case['sale']['propiedad_id']) === ($kind === 'sale' ? 'Vendido' : 'Disponible'),
            'Wrong property state after process recovery.');
        if ($kind === 'abort') { continue; }
        $saleId = $progress['venta_id'];
        [$status, $sale] = callF5('GET', "ventas/$saleId");
        checkF5($status === 200 && $sale['estado'] === ($kind === 'sale' ? 'Completada' : 'Anulada')
            && count($sale['pagos']) === 1 && $sale['pagos'][0]['estado'] === ($kind === 'sale' ? 'PENDIENTE_PAGO' : 'ANULADO')
            && count($sale['plan_pago']['cuotas']) === 3, 'Recovered finance had duplicate, missing or payable obligations.');
        [$status, $contracts] = callF5('GET', 'contratos?per_page=100');
        checkF5($status === 200 && count(array_filter($contracts['data'], fn ($row) => $row['nota_venta_id'] === $saleId)) === 1,
            'Recovered sale duplicated the contract.');
    }
    echo 'F5 real crashes: '.count($cases)." operations resumed and reconciled ($checks assertions).\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'F5 crash: '.$error->getMessage().PHP_EOL);
    exit(1);
}

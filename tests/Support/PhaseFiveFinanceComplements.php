<?php

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\Utils;

define('F5_ACCEPTANCE_HELPERS_ONLY', true);
require __DIR__.'/PhaseFiveAcceptance.php';

function creditF5(string $currency = 'BOB', int $initial = 100): array
{
    return array_replace(saleF5('1000.01', $currency), ['tipo_venta' => 'CREDITO',
        'cuota_inicial' => $initial, 'saldo_credito' => round(1000.01 - $initial, 2), 'numero_cuotas' => 3,
        'tasa_interes' => 12, 'tasa_mora' => 2, 'fecha_inicio_pago' => '2028-01-31']);
}

function planF5(int $id): array
{
    [$status, $plan] = callF5('GET', "ventas/$id/plan-pago");
    checkF5($status === 200, 'Credit fixture plan unavailable.');

    return $plan['plan_pago'];
}

function vectorF5(array $plan): array
{
    return array_map(fn ($q) => [substr($q['fecha_vencimiento'], 0, 10), round((float) $q['monto_capital'], 2),
        round((float) $q['monto_interes'], 2), round((float) $q['monto_cuota'], 2),
        round((float) $q['saldo_capital'], 2)], $plan['cuotas']);
}

try {
    $transport = new CurlMultiHandler(['select_timeout' => 0.1]);
    $remote = endpointF5('PHASE5_API');
    $finance = endpointF5('PHASE5_FINANCE');
    $client = endpointF5('PHASE5_API_LOCAL');
    $token = loginF5();
    $localCredit = creditF5();
    [$status, $created] = callF5('POST', 'ventas', $localCredit);
    checkF5($status === 201, 'Credit fixture Laravel failed.');
    $localSchedule = vectorF5(planF5($created['data']['id']));

    $client = $remote;
    $token = loginF5();
    $operator = $token;
    [$status, $seller] = callF5('POST', 'login', ['correo' => 'sales-only@example.test', 'password' => 'Test-only-Password-42!']);
    checkF5($status === 200, 'Sales-only actor could not authenticate.');
    $token = $seller['access_token'];
    [$status, $selectors] = callF5('GET', 'comercial/opciones');
    checkF5($status === 200 && isset($selectors['clientes'][0], $selectors['asesores'][0], $selectors['propiedades'][0]),
        'Seller could not load the sale selectors.');
    foreach ($selectors['clientes'] as $option) {
        checkF5(array_diff(array_keys($option), ['id', 'nombre_completo', 'ci', 'telefono', 'estado']) === [],
            'Sale selector returned customer fields outside the sale contract.');
    }
    foreach ($selectors['asesores'] as $option) {
        checkF5(array_diff(array_keys($option), ['id', 'user_id', 'nombre_completo', 'correo', 'porcentaje_comision', 'estado']) === [],
            'Sale selector returned adviser fields outside the sale contract.');
    }
    [$list] = callF5('GET', 'clientes');
    [$write] = callF5('POST', 'clientes', operationF5());
    checkF5($list === 403 && $write === 403, 'Sales-only actor was allowed to administer clients.');
    $token = $operator;
    echo "F5-C07: the sales-only actor can choose a customer and adviser without reading or writing the customer module.\n";

    $saleId = completedSaleF5(creditF5());
    $plan = planF5($saleId);
    $remoteSchedule = vectorF5($plan);
    checkF5($remoteSchedule === $localSchedule && round(array_sum(array_column($remoteSchedule, 1)), 2) === 900.01
        && $remoteSchedule[1][0] === '2028-03-02' && $remoteSchedule[2][4] === 0.0,
        'Rate, rounding or leap-year due dates differ between authorities.');
    echo "F5-S03: MySQL and SQL Server agree on interest, capital, dates and the final cent of a positive-rate credit.\n";

    $zeroId = completedSaleF5(creditF5('BOB', 0));
    [$status, $zero] = callF5('GET', "pagos?nota_venta_id=$zeroId&per_page=100");
    checkF5($status === 200 && count($zero['data']) <= 1 && array_sum(array_column($zero['data'], 'monto')) == 0
        && count(paymentsF5($zeroId)) === 0, 'Zero initial amount was treated as a payment.');
    [$status, $positive] = callF5('GET', "pagos?nota_venta_id=$saleId");
    checkF5($status === 200 && count($positive['data']) === 1 && $positive['data'][0]['estado'] === 'PENDIENTE_PAGO'
        && (float) $positive['data'][0]['monto'] === 100.0, 'Positive initial amount was not one pending obligation.');
    $paymentId = $positive['data'][0]['id'];
    echo "F5-F01: zero and positive upfront credits expose the correct pending amount and neither records income on sale.\n";

    $method = ['metodo_pago_id' => 1, 'cuenta_id' => 1, 'fecha_pago' => '2026-11-05'];
    $foreignQuota = planF5($zeroId)['cuotas'][0]['id'];
    $invalid = operationF5() + $method + ['nota_venta_id' => $saleId, 'cuota_id' => $foreignQuota, 'monto' => 5];
    [$status] = callF5('POST', 'pagos', $invalid);
    checkF5($status === 409 && ! paymentsF5($saleId) && ! paymentsF5($zeroId), 'Foreign installment was payable.');
    $legacy = asyncF5($finance, $token, 'POST', 'ventas/1999999999/registrar', operationF5())->wait();
    $legacyCancel = asyncF5($finance, $token, 'POST', "ventas/$saleId/anular", operationF5())->wait();
    checkF5($legacy->getStatusCode() === 503 && $legacyCancel->getStatusCode() === 503 && ! paymentsF5($saleId),
        'Legacy finance route bypassed the coordinated protocol.');
    echo "F5-S16: real legacy registration/cancellation routes could not create or close obligations independently.\n";

    $settle = operationF5();
    [$paid, $voided] = Utils::unwrap([
        asyncF5($finance, $token, 'POST', "pagos/$paymentId/procesar", $settle + $method + ['solicitud_id' => $settle['command_id']]),
        asyncF5($finance, $token, 'PUT', "pagos/$paymentId/cancelar", operationF5()),
    ]);
    $cancelStatus = $voided->getStatusCode();
    checkF5($paid->getStatusCode() === 200 && in_array($cancelStatus, [200, 409], true), 'Payment/reversal race did not serialize.');
    [$status, $pending] = callF5('GET', "pagos?nota_venta_id=$saleId");
    $rows = $pending['data'];
    checkF5($status === 200 && count(array_filter($rows, fn ($p) => $p['estado'] === ($cancelStatus === 200 ? 'PENDIENTE_PAGO' : 'PAGADO'))) === 1
        && count(paymentsF5($saleId)) === ($cancelStatus === 200 ? 0 : 1), 'Payment/reversal race altered the obligation.');
    [$status, $income] = callF5('GET', 'ingresos?per_page=100');
    $entries = array_values(array_filter($income['data'], fn ($row) => ($row['pago_id'] ?? null) === $paymentId));
    checkF5($status === 200 && count($entries) === 1 && $entries[0]['estado'] === ($cancelStatus === 200 ? 'ANULADO' : 'CONFIRMADO'),
        'Payment/reversal race left inconsistent income.');
    echo "F5-F09: concurrent confirmation and reversal of the same receipt preserve the obligation and ledger history.\n";

    $usdId = completedSaleF5(creditF5('USD', 0));
    $usd = planF5($usdId);
    $planId = $usd['id'];
    $oldQuota = $usd['cuotas'][0]['id'];
    $amort = operationF5() + ['monto' => 100, 'metodo_pago_id' => 1, 'cuenta_id' => 2, 'fecha_pago' => '2026-11-05'];
    [$status] = callF5('POST', "planes-pago/$planId/amortizar", array_replace($amort, ['cuenta_id' => 1]));
    checkF5($status === 422 && count(paymentsF5($usdId)) === 0, 'USD amortization accepted a BOB account.');
    $reschedule = operationF5() + ['motivo' => 'Revisión concurrente ficticia', 'fecha_reprogramacion' => '2026-11-05',
        'cuota_desde' => 1, 'nueva_tasa_interes' => 9, 'nuevo_numero_cuotas' => 2, 'nueva_fecha_inicio' => '2027-01-31'];
    [$reduced, $revised] = Utils::unwrap([
        asyncF5($finance, $token, 'POST', "planes-pago/$planId/amortizar", $amort + ['solicitud_id' => $amort['command_id']]),
        asyncF5($finance, $token, 'POST', "planes-pago/$planId/reprogramar", $reschedule + ['solicitud_id' => $reschedule['command_id']]),
    ]);
    checkF5($reduced->getStatusCode() === 200 && $revised->getStatusCode() === 200, 'Concurrent amortization/reschedule failed.');
    $new = planF5($usdId);
    $active = array_values(array_filter($new['cuotas'], fn ($row) => $row['estado'] === 'Pendiente'));
    checkF5(count($active) === 2 && round(array_sum(array_column($active, 'monto_capital')), 2) === 900.01
        && count($new['reprogramaciones']) === 1, 'Concurrent revisions duplicated or lost capital.');
    [$status] = callF5('POST', 'pagos', array_replace(operationF5() + $method, ['nota_venta_id' => $usdId, 'cuota_id' => $oldQuota, 'monto' => 5, 'cuenta_id' => 2]));
    checkF5($status === 409 && count(paymentsF5($usdId)) === 1, 'A replaced installment could still be paid.');
    $paidId = paymentsF5($usdId)[0]['id'];
    [$status, $income] = callF5('GET', 'ingresos?per_page=100');
    $entry = array_values(array_filter($income['data'], fn ($row) => ($row['pago_id'] ?? null) === $paidId));
    checkF5($status === 200 && count($entry) === 1 && in_array($entry[0]['moneda'], ['USD', '$'], true)
        && (float) $entry[0]['monto'] === 100.0, 'USD amortization ledger currency or amount is incorrect.');
    echo "F5-F06/F10/F11: foreign and replaced installments rejected, USD account verified, and concurrent plan writes preserve one revision and the remaining capital.\n";
    echo "F5 finance complements: $checks assertions passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'F5 finance complements: '.$error->getMessage().PHP_EOL);
    exit(1);
}

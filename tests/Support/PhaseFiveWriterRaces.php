<?php

use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;

// Included by the isolated F5 acceptance driver after it authenticates the fixture operator.
$first = saleF5();
$second = operationF5() + array_replace($first, ['cliente_id' => waitF5($numeric['operacion_id'])['resource_id']]);
$boundary = checkpointF5($first['operacion_id'], 'Reservar', 'before');
$otherBoundary = checkpointF5($second['operacion_id'], 'Reservar', 'before');
[$status] = callF5('POST', 'ventas', $first);
checkF5($status === 202, 'First buyer sale not admitted.');
try {
    hitF5($boundary);
    [$status] = callF5('POST', 'ventas', $second);
    checkF5($status === 202, 'Second buyer sale not admitted.');
    hitF5($otherBoundary);
    checkF5(file_get_contents($boundary.'.hit') !== file_get_contents($otherBoundary.'.hit'), 'Both reservation requests must originate in distinct workers.');
} finally {
    file_put_contents($boundary.'.release', '');
    file_put_contents($otherBoundary.'.release', '');
}
$outcomes = [waitF5($first['operacion_id']), waitF5($second['operacion_id'])];
$won = array_values(array_filter($outcomes, fn ($op) => $op['state'] === 'Completada'));
checkF5(count($won) === 1, 'The simultaneous buyers did not yield exactly one winner.');
$winner = $won[0]['venta_id'];
$lost = array_values(array_filter($outcomes, fn ($op) => $op['state'] !== 'Completada'))[0];
checkF5(in_array($lost['state'], ['Compensada', 'Rechazada'], true) && ! ($lost['venta_id'] ?? null), 'The other buyer kept a sale without the reservation.');
[$status, $sameProperty] = callF5('GET', 'ventas?tipo_venta=CONTADO&per_page=100');
$winners = array_values(array_filter($sameProperty['data'], fn ($sale) => $sale['propiedad_id'] === $first['propiedad_id'] && $sale['estado'] === 'Completada'));
checkF5($status === 200 && count($winners) === 1 && $winners[0]['id'] === $winner
    && propertyStateF5($first['propiedad_id']) === 'Vendido', 'Competing buyer was able to take the confirmed property.');
echo "F5-S04: two workers sent competing reservations for one property; one sale confirmed and the loser kept no obligation.\n";

$stale = saleF5();
$propertyId = $stale['propiedad_id'];
$boundary = checkpointF5($stale['operacion_id'], 'Reservar', 'before');
[$status] = callF5('POST', 'ventas', $stale);
checkF5($status === 202, 'Stale offer fixture was not admitted.');
try {
    hitF5($boundary);
    [$status, $property] = callF5('GET', "propiedades/$propertyId");
    checkF5($status === 200, 'Cannot edit unavailable offer fixture.');
    $updated = $remote->put("/api/propiedades/$propertyId", [
        'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json',
            'If-Match' => '"'.$property['version'].'"', 'Idempotency-Key' => uuidF5()],
        'json' => ['propietario_ids' => array_column($property['propietarios'], 'id'), 'sector_urbano_id' => $property['sector_urbano_id'],
            'tipo' => 'Lote', 'moneda' => 'USD', 'precio_venta' => '12000.00', 'superficie_m2' => '200.00', 'activo' => true],
    ]);
    checkF5($updated->getStatusCode() === 200, 'Could not change offer price, currency and version.');
} finally {
    file_put_contents($boundary.'.release', '');
}
$blocked = waitF5($stale['operacion_id']);
checkF5(in_array($blocked['state'], ['Compensada', 'Rechazada'], true) && ! ($blocked['venta_id'] ?? null)
    && propertyStateF5($propertyId) === 'Disponible', 'Stale offer was charged or confirmed.');
echo "F5-S05: changing price, currency and version between offer and reservation blocked the old sale before accounting.\n";

$credit = array_replace(saleF5(), ['tipo_venta' => 'CREDITO', 'monto_liquido' => null, 'cuota_inicial' => 0, 'numero_cuotas' => 3,
    'tasa_interes' => 12, 'tasa_mora' => 3, 'fecha_inicio_pago' => '2026-12-31']);
$creditId = completedSaleF5($credit);
[$status, $schedule] = callF5('GET', "ventas/$creditId/plan-pago");
checkF5($status === 200 && count($schedule['plan_pago']['cuotas']) === 3, 'Finance races schedule missing.');
$quotas = $schedule['plan_pago']['cuotas'];
$finance = endpointF5('PHASE5_FINANCE');
$form = ['nota_venta_id' => $creditId, 'cuota_id' => $quotas[0]['id'], 'concepto_pago' => 'CUOTA',
    'metodo_pago_id' => 1, 'cuenta_id' => 1, 'monto' => 2500, 'fecha_pago' => '2027-02-05'];
$requests = [];
for ($i = 0; $i < 2; $i++) {
    $command = operationF5();
    $requests[] = asyncF5($finance, $token, 'POST', 'pagos', $command + $form + ['solicitud_id' => $command['command_id']]);
}
$results = array_map(fn (ResponseInterface $response) => $response->getStatusCode(), Utils::unwrap($requests));
sort($results);
$payments = paymentsF5($creditId);
checkF5($results === [200, 422] && count($payments) === 1 && (float) $payments[0]['monto'] === 2500.0, 'Two parallel partial payments overpaid the installment.');
[$status, $after] = callF5('GET', "ventas/$creditId/plan-pago");
checkF5($status === 200 && (float) $after['plan_pago']['cuotas'][0]['monto_pendiente'] === round((float) $quotas[0]['monto_cuota'] - 2500, 2),
    'Concurrent partials produced incorrect pending balance.');
echo "F5-F04: two SQL writers competed for the same installment, preserving one paid amount and positive balance.\n";

[$status, $beforeIncome] = callF5('GET', 'ingresos?per_page=100');
checkF5($status === 200, 'Ledger unavailable before batch.');
$batch = operationF5() + ['nota_venta_id' => $creditId, 'metodo_pago_id' => 1, 'cuenta_id' => 1, 'fecha_pago' => '2027-02-05',
    'cuotas' => [
        ['cuota_id' => $quotas[0]['id'], 'monto' => 10, 'mora_monto' => 1],
        ['cuota_id' => $quotas[1]['id'], 'monto' => 50000],
    ]];
[$status] = callF5('POST', 'pagos/bulk', $batch);
checkF5($status === 422 && count(paymentsF5($creditId)) === 1, 'Failed middle of batch left a payment or late fee.');
[$status, $afterIncome] = callF5('GET', 'ingresos?per_page=100');
checkF5($status === 200 && $afterIncome['meta']['total'] === $beforeIncome['meta']['total'], 'Failed batch wrote a ledger entry.');
$batch['cuotas'][1]['monto'] = 10;
[$status] = callF5('POST', 'pagos/bulk', $batch);
[$replayStatus] = callF5('POST', 'pagos/bulk', $batch);
$paid = paymentsF5($creditId);
checkF5($status === 200 && $replayStatus === 200 && count($paid) === 4
    && count(array_filter($paid, fn ($p) => $p['concepto_pago'] === 'MORA')) === 1, 'Successful batch retry duplicated the late fee.');
[$status, $afterIncome] = callF5('GET', 'ingresos?per_page=100');
checkF5($status === 200 && $afterIncome['meta']['total'] === $beforeIncome['meta']['total'] + 3, 'Confirmed batch ledger was duplicated.');
echo "F5-F05: second-item failure rolled back the first payment and late fee; successful replay created exactly three movements.\n";

foreach (['before', 'after'] as $side) {
    $offer = saleF5();
    $saleId = completedSaleF5($offer);
    [$status, $outstanding] = callF5('GET', 'comisiones-asesores/1/impagas?per_page=100');
    $expenses = array_values(array_filter($outstanding['data'] ?? [], fn ($row) => $row['nota_venta_id'] === $saleId));
    checkF5($status === 200 && count($expenses) === 1, 'Missing initial commission obligation.');
    $expense = $expenses[0]['id'];
    $cancel = operationF5();
    $boundary = checkpointF5($cancel['operacion_id'], 'CerrarFinanzas', $side);
    [$status] = callF5('PUT', "ventas/$saleId/anular", $cancel);
    checkF5($status === 202, 'Commission/closure fixture did not admit cancellation.');
    try {
        hitF5($boundary);
        $requests = [];
        foreach (['first', 'second'] as $label) {
            $payout = operationF5() + ['cuenta_id' => 1, 'fecha' => '2026-11-05', 'observaciones' => "Payout fixture $label"];
            $requests[] = asyncF5($finance, $token, 'PUT', "egresos/$expense/pagar", $payout);
        }
        $results = array_map(fn (ResponseInterface $response) => $response->getStatusCode(), Utils::unwrap($requests));
        sort($results);
        checkF5($results === ($side === 'before' ? [200, 409] : [409, 409]), 'Parallel commission/close result was unsafe.');
    } finally {
        file_put_contents($boundary.'.release', '');
    }
    $done = waitF5($cancel['operacion_id']);
    checkF5($done['state'] === ($side === 'before' ? 'Rechazada' : 'Completada')
        && propertyStateF5($offer['propiedad_id']) === ($side === 'before' ? 'Vendido' : 'Disponible'), 'Commission/close violated property consistency.');
    [$status, $paidCommissions] = callF5('GET', 'comisiones-asesores/1/pagadas?per_page=100');
    checkF5($status === 200 && count(array_filter($paidCommissions['data'], fn ($row) => $row['nota_venta_id'] === $saleId)) === ($side === 'before' ? 1 : 0),
        'Commission replay produced a duplicate payout.');
    if ($side === 'before') {
        $receipt = $remote->get("/api/comisiones-asesores/egreso/$expense/comprobante", ['headers' => ['Authorization' => 'Bearer '.$token]]);
        checkF5($receipt->getStatusCode() === 200 && str_starts_with((string) $receipt->getBody(), '%PDF-'), 'Winning commission had no receipt.');
    }
}
echo "F5-N04/F17: closure competed with two disbursement requests and preserved a single payout and receipt or terminal cancellation.\n";

foreach (['delivery', 'cancellation', 'parallel'] as $firstWriter) {
    $offer = saleF5();
    $saleId = completedSaleF5($offer);
    [$status, $contracts] = callF5('GET', 'contratos?per_page=100');
    $contract = array_values(array_filter($contracts['data'], fn ($row) => $row['nota_venta_id'] === $saleId))[0];
    $signed = operationF5() + ['firmado' => true, 'fecha_firma' => '2026-11-04', 'fecha_programada_entrega' => '2026-11-06'];
    [$status] = callF5('POST', 'contratos/'.$contract['id'].'/gestionar', $signed);
    checkF5($status === 200, 'Could not sign delivery race fixture: '.$status);
    [$status, $deliveries] = callF5('GET', 'entregas?per_page=100');
    $delivery = array_values(array_filter($deliveries['data'], fn ($row) => $row['contrato_id'] === $contract['id']))[0];
    $deliveryId = $delivery['id'];
    $complete = operationF5() + ['estado' => 'Entregado', 'fecha_programada' => '2026-11-06', 'fecha_entrega' => '2026-11-06',
        'entregado_por' => 'Operador de prueba', 'recibido_por' => 'Cliente de prueba'];
    $cancel = operationF5();
    if ($firstWriter === 'delivery') {
        [$delivered] = callF5('PUT', "entregas/$deliveryId", $complete);
        [$cancelled] = callF5('PUT', "ventas/$saleId/anular", $cancel);
        checkF5($delivered === 200 && $cancelled === 409 && propertyStateF5($offer['propiedad_id']) === 'Vendido',
            'An already delivered property could be released.');
    } elseif ($firstWriter === 'cancellation') {
        $boundary = checkpointF5($cancel['operacion_id'], 'CerrarFinanzas', 'before');
        [$status] = callF5('PUT', "ventas/$saleId/anular", $cancel);
        checkF5($status === 202, 'Cancellation competing with delivery was not admitted.');
        try {
            hitF5($boundary);
            [$delivered] = callF5('PUT', "entregas/$deliveryId", $complete);
            checkF5($delivered === 409, 'Delivery was allowed during an admitted cancellation.');
        } finally {
            file_put_contents($boundary.'.release', '');
        }
        checkF5(waitF5($cancel['operacion_id'])['state'] === 'Completada' && propertyStateF5($offer['propiedad_id']) === 'Disponible',
            'Cancellation and delivery reached inconsistent states.');
    } else {
        $commercial = endpointF5('PHASE5_COMMERCIAL');
        [$deliveryResponse, $cancelResponse] = Utils::unwrap([
            asyncF5($commercial, $token, 'PUT', "entregas/$deliveryId", $complete),
            asyncF5($commercial, $token, 'POST', "operaciones/ventas/$saleId/anular", $cancel),
        ]);
        $delivered = $deliveryResponse->getStatusCode();
        $cancelled = $cancelResponse->getStatusCode();
        checkF5(in_array([$delivered, $cancelled], [[200, 409], [409, 202]], true), 'Parallel delivery and cancellation returned an unsafe pair.');
        $firstWriter = $delivered === 200 ? 'delivery' : 'cancellation';
        if ($firstWriter === 'cancellation') {
            checkF5(waitF5($cancel['operacion_id'])['state'] === 'Completada', 'Winning parallel cancellation did not complete.');
        }
        checkF5(propertyStateF5($offer['propiedad_id']) === ($firstWriter === 'delivery' ? 'Vendido' : 'Disponible'),
            'Parallel delivery and cancellation left a wrong property state.');
    }
    [$status, $deliveries] = callF5('GET', 'entregas?per_page=100');
    $state = array_column($deliveries['data'] ?? [], 'estado', 'id')[$deliveryId] ?? null;
    checkF5($status === 200 && $state === ($firstWriter === 'delivery' ? 'Entregado' : 'Anulada'), 'Terminal delivery state is inconsistent.');
}
echo "F5-N05: simultaneous HTTP writers and both explicit orderings preserved a single delivery/cancellation outcome.\n";

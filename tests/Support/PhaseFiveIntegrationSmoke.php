<?php
require __DIR__.'/../../vendor/autoload.php';
if (getenv('MULTILIDER_TEST_ISOLATED') !== '1') { throw new RuntimeException('Isolated runner required.'); }
$client = new GuzzleHttp\Client(['base_uri' => getenv('PHASE5_API'), 'http_errors' => false, 'timeout' => 15]);
$token = null; $checks = 0;
function callF5(string $method, string $path, ?array $body = null): array
{
    global $client, $token;
    $headers = ['Accept' => 'application/json'];
    if ($token) { $headers['Authorization'] = 'Bearer '.$token; }
    if (! empty($body['command_id'])) { $headers['Idempotency-Key'] = $body['command_id']; }
    $r = $client->request($method, '/api/'.$path, ['headers' => $headers] + ($body === null ? [] : ['json' => $body]));
    return [$r->getStatusCode(), json_decode((string) $r->getBody(), true)];
}
function checkF5(bool $value, string $message): void { global $checks; if (! $value) { throw new RuntimeException($message); } $checks++; }
function waitF5(string $id, int $seconds = 18): array
{
    $deadline = microtime(true) + $seconds;
    do {
        [$status, $body] = callF5('GET', 'operaciones/'.$id);
        if ($status !== 200) { throw new RuntimeException('Progress query failed: '.$status); }
        if (in_array($body['state'] ?? '', ['Completada', 'Rechazada', 'Compensada', 'RequiereRevision'], true)) { return $body; }
        usleep(100000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Operation did not reach terminal state: '.($body['step'] ?? '').'/'.($body['error_code'] ?? '').'; attempts='.($body['attempts'] ?? ''));
}
function uuidF5(): string { return (string) Illuminate\Support\Str::uuid(); }
if (defined('F5_HTTP_HELPERS_ONLY')) { return; }
try {
    [$status, $login] = callF5('POST', 'login', ['correo' => 'operator@example.test', 'password' => 'Test-only-Password-42!']);
    checkF5($status === 200 && isset($login['access_token']), 'Login failed.');
    $token = $login['access_token'];
    [$status, $options] = callF5('GET', 'comercial/opciones');
    checkF5($status === 200 && count($options['propiedades']) === 1, 'Sale selectors failed.');
    $property = $options['propiedades'][0]; $op = uuidF5();
    $sale = ['operacion_id' => $op, 'command_id' => uuidF5(), 'cliente_id' => 1, 'asesor_id' => 1, 'propiedad_id' => $property['id'],
        'fecha' => '2026-11-04', 'tipo_venta' => 'CONTADO', 'monto_total' => 20000, 'monto_liquido' => 20000];
    [$status, $admitted] = callF5('POST', 'ventas', $sale);
    checkF5($status === 202, 'Sale admission failed: '.$status.' '.json_encode($admitted));
    $result = waitF5($op); checkF5($result['state'] === 'Completada', 'Sale failed: '.($result['error_code'] ?? $result['state']));
    $venta = $result['venta_id'];
    [$status, $offer] = callF5('GET', 'propiedades/'.$property['id']);
    checkF5($status === 200 && $offer['estado'] === 'Vendido', 'Property was not confirmed.');
    $pay = ['operacion_id' => uuidF5(), 'command_id' => uuidF5(), 'nota_venta_id' => $venta, 'monto' => 100, 'metodo_pago_id' => 1, 'cuenta_id' => 1, 'fecha_pago' => '2026-11-04'];
    [$status, $paid] = callF5('POST', 'pagos', $pay); checkF5($status === 200, 'Partial payment failed.');
    $payment = $paid['data']['id'];
    [$status, $again] = callF5('POST', 'pagos', $pay); checkF5($status === 200 && $again['data']['id'] === $payment, 'Payment replay duplicated.');
    $cancel = ['operacion_id' => uuidF5(), 'command_id' => uuidF5()];
    [$status] = callF5('PUT', "ventas/$venta/anular", $cancel); checkF5($status === 202, 'Cancel admission failed.');
    $rejected = waitF5($cancel['operacion_id']); checkF5($rejected['state'] === 'Rechazada', 'Paid sale must not release property.');
    [$status] = callF5('PUT', "pagos/$payment/cancelar", ['operacion_id' => uuidF5(), 'command_id' => uuidF5()]);
    checkF5($status === 200, 'Payment reversal failed.');
    $cancel = ['operacion_id' => uuidF5(), 'command_id' => uuidF5()];
    [$status] = callF5('PUT', "ventas/$venta/anular", $cancel); checkF5($status === 202, 'Second cancellation admission failed.');
    $done = waitF5($cancel['operacion_id']); checkF5($done['state'] === 'Completada', 'Cancellation failed: '.($done['error_code'] ?? $done['state']));
    [$status, $offer] = callF5('GET', 'propiedades/'.$property['id']); checkF5($status === 200 && $offer['estado'] === 'Disponible', 'Financial closure did not release own generation.');
    $customerOp = uuidF5();
    [$status, $created] = callF5('POST', 'clientes', ['operacion_id' => $customerOp, 'command_id' => uuidF5(),
        'ci' => 'F5-CLIENT-NEW', 'lugar_expedicion' => 'LP', 'nombre_completo' => 'Cliente integrado F5',
        'correo' => 'client-f5@example.test', 'estado' => true]);
    checkF5(in_array($status, [200, 201, 202], true), 'Coordinated client creation failed: '.$status);
    $customer = $status === 202 ? waitF5($customerOp)['resource_id'] : $created['data']['id'];
    [$status] = callF5('POST', 'login', ['correo' => 'client-f5@example.test', 'password' => 'F5-CLIENT-NEW']);
    checkF5($status === 200, 'Provisioned client cannot authenticate.');
    $creditOp = uuidF5();
    [$status] = callF5('POST', 'ventas', ['operacion_id' => $creditOp, 'command_id' => uuidF5(), 'cliente_id' => $customer,
        'asesor_id' => 1, 'propiedad_id' => $property['id'], 'fecha' => '2026-11-04', 'tipo_venta' => 'CREDITO',
        'monto_total' => 20000, 'cuota_inicial' => 2000, 'numero_cuotas' => 3, 'tasa_interes' => 10, 'tasa_mora' => 3, 'fecha_inicio_pago' => '2026-12-31']);
    checkF5($status === 202, 'Credit admission failed.');
    $credit = waitF5($creditOp); checkF5($credit['state'] === 'Completada', 'Credit saga failed.');
    $creditId = $credit['venta_id'];
    [$status, $plan] = callF5('GET', "ventas/$creditId/plan-pago");
    checkF5($status === 200 && count($plan['plan_pago']['cuotas']) === 3, 'Financial schedule missing.');
    $quotas = $plan['plan_pago']['cuotas'];
    checkF5($quotas[1]['fecha_vencimiento'] === '2027-01-31' && $quotas[2]['fecha_vencimiento'] === '2027-03-03', 'Legacy month overflow changed.');
    $partial = ['operacion_id' => uuidF5(), 'command_id' => uuidF5(), 'nota_venta_id' => $creditId, 'cuota_id' => $quotas[0]['id'],
        'monto' => 100, 'metodo_pago_id' => 1, 'cuenta_id' => 1, 'fecha_pago' => '2026-11-04'];
    [$status, $partialResult] = callF5('POST', 'pagos', $partial);
    checkF5($status === 200, 'Partial installment failed.');
    [$status] = callF5('POST', 'pagos/bulk', ['operacion_id' => uuidF5(), 'command_id' => uuidF5(), 'nota_venta_id' => $creditId,
        'metodo_pago_id' => 1, 'cuenta_id' => 1, 'fecha_pago' => '2026-11-04',
        'cuotas' => [['cuota_id' => $quotas[0]['id'], 'monto' => round((float) $quotas[0]['monto_cuota'] - 100, 2)], ['cuota_id' => $quotas[1]['id'], 'monto' => (float) $quotas[1]['monto_cuota']]]]);
    checkF5($status === 200, 'Atomic installment batch failed.');
    $download = $client->get('/api/pagos/'.$partialResult['data']['id'].'/comprobante', ['headers' => ['Authorization' => 'Bearer '.$token]]);
    $pdf = (string) $download->getBody();
    checkF5($download->getStatusCode() === 200 && str_starts_with($pdf, '%PDF-'), 'Laravel payment receipt failed.');
    file_put_contents(__DIR__.'/../../storage/framework/testing/f5-payment-receipt.pdf', $pdf);
    [$status, $contracts] = callF5('GET', 'contratos');
    checkF5($status === 200, 'Contract list failed.');
    $contract = null;
    foreach ($contracts['data'] as $row) { if ($row['nota_venta_id'] === $creditId) { $contract = $row; break; } }
    checkF5($contract !== null, 'Coordinated contract absent.');
    $download = $client->get('/api/contratos/'.$contract['id'].'/generar-pdf', ['headers' => ['Authorization' => 'Bearer '.$token]]);
    $pdf = (string) $download->getBody();
    checkF5($download->getStatusCode() === 200 && str_starts_with($pdf, '%PDF-'), 'Laravel legal template failed.');
    file_put_contents(__DIR__.'/../../storage/framework/testing/f5-contract.pdf', $pdf);
    [$status, $history] = callF5('GET', 'ventas?tipo_venta=CREDITO');
    checkF5($status === 200 && count($history['data']) === 1 && isset($history['data'][0]['propiedad']['codigo'], $history['data'][0]['plan_pago']['cuotas'], $history['data'][0]['pagos']), 'Operational sale DTO or filters failed.');
    [$status, $income] = callF5('GET', 'ingresos');
    checkF5($status === 200 && isset($income['meta']['total']) && count($income['data']) === 4, 'Ledger compatibility shape failed.');
    $customerUpdate = uuidF5();
    [$status] = callF5('PUT', 'clientes/'.$customer, ['operacion_id' => $customerUpdate, 'command_id' => uuidF5(),
        'ci' => 'F5-CLIENT-NEW', 'lugar_expedicion' => 'LP', 'nombre_completo' => 'Cliente actualizado F5', 'correo' => 'client-f5-updated@example.test', 'estado' => true]);
    checkF5(in_array($status, [200, 202], true) && waitF5($customerUpdate)['state'] === 'Completada', 'Coordinated profile update failed.');
    [$status, $clientLogin] = callF5('POST', 'login', ['correo' => 'client-f5-updated@example.test', 'password' => 'F5-CLIENT-NEW']);
    checkF5($status === 200, 'Updated identity cannot authenticate.');
    $operatorToken = $token; $token = $clientLogin['access_token'];
    [$status] = callF5('GET', 'operaciones/'.$creditOp);checkF5($status === 403, 'An unrelated user could inspect operation progress.');
    $token = $operatorToken;
    $file = "%PDF-1.4\n% F5 synthetic attachment\n%%EOF";
    $command = uuidF5(); $attachmentOp = uuidF5();
    $multipart = [
        ['name' => 'archivo', 'contents' => $file, 'filename' => 'contrato-prueba.pdf'], ['name' => 'firmado', 'contents' => '1'],
        ['name' => 'fecha_firma', 'contents' => '2026-11-04'], ['name' => 'fecha_programada_entrega', 'contents' => '2026-11-06'],
        ['name' => 'operacion_id', 'contents' => $attachmentOp], ['name' => 'command_id', 'contents' => $command],
    ];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $response = $client->post('/api/contratos/'.$contract['id'].'/gestionar', ['headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, 'Idempotency-Key' => $command], 'multipart' => $multipart]);
        checkF5($response->getStatusCode() === 200, 'Multipart contract operation failed.');
    }
    $download = $client->get('/api/contratos/'.$contract['id'].'/descargar', ['headers' => ['Authorization' => 'Bearer '.$token]]);
    checkF5($download->getStatusCode() === 200 && (string) $download->getBody() === $file, 'Private PDF attachment download failed.');
    [$status, $deliveries] = callF5('GET', 'entregas');
    checkF5($status === 200 && count($deliveries['data']) === 1 && isset($deliveries['data'][0]['contrato']['nota_venta']['cliente']), 'Signature did not produce one complete delivery DTO.');
    [$status, $receipt] = callF5('GET', 'operaciones/'.$attachmentOp);
    checkF5($status === 200 && $receipt['state'] === 'Completada', 'Multipart operation receipt unavailable.');
    $delivery = $deliveries['data'][0]['id'];
    $response = $client->post('/api/entregas/'.$delivery.'/subir-acta', ['headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, 'Idempotency-Key' => uuidF5()],
        'multipart' => [['name' => 'acta', 'contents' => $file, 'filename' => 'acta-fixture.pdf']]]);
    checkF5($response->getStatusCode() === 200, 'Delivery attachment field compatibility failed.');
    $contractId = $contract['id'];
    foreach (["contratos/$contractId/descargar", "entregas/$delivery/descargar-acta"] as $path) {
        $allowed = $client->get('/api/'.$path, ['headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']]);
        $foreign = $client->get('/api/'.$path, ['headers' => ['Authorization' => 'Bearer '.$clientLogin['access_token'], 'Accept' => 'application/json']]);
        $anonymous = $client->get('/api/'.$path, ['headers' => ['Accept' => 'application/json']]);
        checkF5($allowed->getStatusCode() === 200 && (string) $allowed->getBody() === $file
            && $foreign->getStatusCode() === 403 && $anonymous->getStatusCode() === 401, 'Foreign client or anonymous request accessed a document by ID.');
    }
    $badDocument = $client->post("/api/contratos/$contractId/gestionar", [
        'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'Idempotency-Key' => uuidF5()],
        'multipart' => [
            ['name' => 'archivo', 'contents' => '<html>not a PDF</html>', 'filename' => '../claimed.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
            ['name' => 'firmado', 'contents' => '1'],
        ],
    ]);
    $stillOriginal = $client->get("/api/contratos/$contractId/descargar", ['headers' => ['Authorization' => 'Bearer '.$token]]);
    checkF5($badDocument->getStatusCode() === 422 && (string) $stillOriginal->getBody() === $file, 'Forged PDF MIME replaced the current document.');
    [$status, $unsigned] = callF5('POST', "contratos/$contractId/gestionar", ['command_id' => uuidF5(), 'operacion_id' => uuidF5(), 'firmado' => false]);
    checkF5($status === 200 && $unsigned['data']['estado'] === 'Pendiente', 'Pending delivery prevented removing an existing signature.');
    [$status] = callF5('PUT', "entregas/$delivery", ['command_id' => uuidF5(), 'operacion_id' => uuidF5(),
        'estado' => 'Diferido', 'fecha_programada' => '2026-11-10']);
    checkF5($status === 409, 'Unsigned contract permitted a delivery change.');
    [$status, $signedAgain] = callF5('POST', "contratos/$contractId/gestionar", ['command_id' => uuidF5(), 'operacion_id' => uuidF5(),
        'firmado' => true, 'fecha_firma' => '2026-11-05', 'fecha_programada_entrega' => '2026-11-10']);
    checkF5($status === 200 && $signedAgain['data']['estado'] === 'Firmado', 'Could not sign the contract again.');
    [$status, $rescheduled] = callF5('GET', 'entregas');
    checkF5($status === 200 && count($rescheduled['data']) === 1 && $rescheduled['data'][0]['id'] === $delivery
        && $rescheduled['data'][0]['fecha_programada'] === '2026-11-10', 'Re-signing duplicated delivery or did not reschedule.');
    echo "F5-D03/D05: signature and re-schedule cycle, and authenticated contract/acta download authorization verified.\n";
    [$status, $commissions] = callF5('GET', 'comisiones-asesores');
    checkF5($status === 200 && $commissions['meta']['total'] === 1 && (float) $commissions['data'][0]['total_deuda_bs'] === 600.0, 'Commission aggregation failed.');
    [$status, $details] = callF5('GET', 'comisiones-asesores/1/impagas');
    checkF5($status === 200 && $details['meta']['total'] === 1 && $details['data'][0]['nota_venta_id'] === $creditId, 'Commission relationship DTO failed.');
    $expense = $details['data'][0]['id'];
    $payout = ['operacion_id' => uuidF5(), 'command_id' => uuidF5(), 'fecha' => '2026-11-05', 'cuenta_bancaria_id' => 2];
    [$status] = callF5('PUT', "egresos/$expense/pagar", $payout);checkF5($status === 422, 'Commission accepted a bank account of another currency.');
    $payout = ['operacion_id' => uuidF5(), 'command_id' => uuidF5(), 'fecha' => '2026-11-05', 'cuenta_bancaria_id' => 1];
    [$status] = callF5('PUT', "egresos/$expense/pagar", $payout);checkF5($status === 200, 'Commission payout failed.');
    [$status] = callF5('PUT', "egresos/$expense/pagar", $payout);checkF5($status === 200, 'Commission payout replay failed.');
    $download = $client->get('/api/comisiones-asesores/egreso/'.$expense.'/comprobante', ['headers' => ['Authorization' => 'Bearer '.$token]]);
    checkF5($download->getStatusCode() === 200 && str_starts_with((string) $download->getBody(), '%PDF-'), 'Laravel commission receipt failed.');
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2ioAAAAASUVORK5CYII=');
    $adviserOperation = uuidF5(); $adviserCommand = uuidF5();
    $fields = ['operacion_id' => $adviserOperation, 'command_id' => $adviserCommand, 'nombre_completo' => 'Asesor prueba F5',
        'correo' => 'adviser-f5@example.test', 'password' => 'Fixture-Strong-Adviser-42!', 'porcentaje_comision' => '2.50', 'estado' => '1'];
    $multipart = array_map(fn ($key, $value) => ['name' => $key, 'contents' => $value], array_keys($fields), array_values($fields));
    $multipart[] = ['name' => 'foto', 'contents' => $image, 'filename' => 'photo-fixture.png'];
    $response = $client->post('/api/asesores', ['headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'Idempotency-Key' => $adviserCommand], 'multipart' => $multipart]);
    checkF5(in_array($response->getStatusCode(), [200, 202], true), 'Adviser multipart provisioning failed.');
    $adviser = waitF5($adviserOperation); checkF5($adviser['state'] === 'Completada', 'Adviser identity did not complete.');
    $photo = $client->get('/api/asesores/'.$adviser['resource_id'].'/foto', ['headers' => ['Authorization' => 'Bearer '.$token]]);
    checkF5($photo->getStatusCode() === 200 && $photo->getHeaderLine('Content-Type') === 'image/png' && (string) $photo->getBody() === $image, 'Adviser private photograph failed.');
    $disable = uuidF5();
    [$status] = callF5('DELETE', 'asesores/'.$adviser['resource_id'], ['operacion_id' => $disable, 'command_id' => uuidF5()]);
    checkF5(in_array($status, [200, 202], true) && waitF5($disable)['state'] === 'Completada', 'Coordinated adviser deactivation failed.');
    [$status] = callF5('POST', 'login', ['correo' => 'adviser-f5@example.test', 'password' => 'Fixture-Strong-Adviser-42!']);
    checkF5(in_array($status, [401, 403], true), 'Deactivated identity still authenticates.');
    echo "F5 real HTTPS/mTLS integration: $checks checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, 'F5 integration: '.$error->getMessage()."\n"); exit(1); }

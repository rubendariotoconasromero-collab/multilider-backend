<?php

define('F5_HTTP_HELPERS_ONLY', true);
require __DIR__.'/PhaseFiveIntegrationSmoke.php';

function requestF6(string $method, string $path, ?array $body = null): array
{
    global $client, $token;
    $response = $client->request($method, '/api/'.$path, [
        'headers' => ['Accept' => 'application/json'] + ($token ? ['Authorization' => 'Bearer '.$token] : []),
    ] + ($body === null ? [] : ['json' => $body]));
    $bytes = (string) $response->getBody();

    return [$response->getStatusCode(), json_decode($bytes, true), $bytes];
}

function loginF6(string $email = 'operator@example.test', string $password = 'Test-only-Password-42!'): void
{
    global $token;
    $token = null;
    [$status, $body] = requestF6('POST', 'login', ['correo' => $email, 'password' => $password]);
    checkF5($status === 200 && isset($body['access_token']), "F6 fixture login failed for $email: $status.");
    $token = $body['access_token'];
}

function expectF6(string $method, string $path, int $expected = 200, ?array $payload = null): array
{
    [$status, $body, $bytes] = requestF6($method, $path, $payload);
    checkF5($status === $expected, "$method $path returned $status instead of $expected. ".($body['message'] ?? ''));

    return [$body, $bytes];
}

function localF6(): void
{
    global $client, $token;
    $client = new GuzzleHttp\Client(['base_uri' => getenv('PHASE5_API_LOCAL'), 'http_errors' => false, 'timeout' => 20]);
    $token = null;
    expectF6('GET', 'landing');
    loginF6();
    expectF6('GET', 'me');
    expectF6('GET', 'empresa/datos');
    expectF6('GET', 'propiedades');
    expectF6('GET', 'dashboard/admin');
    expectF6('GET', 'reportes/inventario-propiedades');
    expectF6('GET', 'reportes/inventario-propiedades/pdf');
    expectF6('GET', 'reportes/inventario-propiedades/excel');
    expectF6('GET', 'correo-masivo/grupos');
}

function verifyReportWorkbook(string $file, string $label, float $expected, string $heading, array $details, int $amountColumn): void
{
    $workbook = PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    $rows = $workbook->getActiveSheet()->toArray();
    $workbook->disconnectWorksheets();
    $value = null;
    $detailStart = null;
    foreach ($rows as $index => $row) {
        $column = array_search($label, $row, true);
        if ($column !== false) {
            $value = $row[$column + 1];
        }
        if ($row[0] === $heading) {
            $detailStart = $index + 1;
        }
    }
    checkF5($value !== null && (float) str_replace(',', '', (string) $value) === $expected, "Workbook total differs: $label.");
    checkF5($detailStart !== null && isset($rows[0][$amountColumn]), "Workbook detail columns missing: $heading.");
    $amounts = array_map(fn ($row) => (float) str_replace(',', '', (string) $row[$amountColumn]),
        array_filter(array_slice($rows, $detailStart), fn ($row) => $row[0] !== null && $row[0] !== ''));
    checkF5(array_values($amounts) === array_map('floatval', $details), "Workbook detail amounts differ: $heading.");
}

try {
    $target = $argv[1] ?? null;
    if ($target !== null) {
        if ($target === 'reservation') {
            $id = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            checkF5($id !== false, 'Invalid reserved fixture ID.');
            loginF6();
            [$catalogProperty] = expectF6('GET', "propiedades/$id");
            [$inventory] = expectF6('GET', 'reportes/inventario-propiedades?estado=Reservado&per_page=100');
            $matching = array_values(array_filter($inventory['propiedades']['data'], fn ($property) => $property['id'] === $id));
            checkF5($catalogProperty['estado'] === 'Reservado' && ($matching[0]['estado'] ?? null) === 'Reservado', 'Reserved property missing from inventory.');
            echo "F6-A09: live reserved property matched the inventory report.\n";
            exit(0);
        } elseif ($target === 'rollback') {
            loginF6();
            expectF6('GET', 'me');
            expectF6('GET', 'landing', 503);
            expectF6('GET', 'reportes/inventario-propiedades', 503);
        } elseif ($target === 'seguridad') {
            expectF6('POST', 'login', 503, ['correo' => 'operator@example.test', 'password' => 'Test-only-Password-42!']);
        } elseif ($target !== 'local-only') {
            loginF6();
            $path = match ($target) {
                'finanzas' => 'reportes/cartera-mora',
                'propiedades' => 'reportes/inventario-propiedades',
                'comercial' => 'landing',
            };
            expectF6('GET', $path, 503);
        }
        localF6();
        if ($target === 'local-only') {
            [$preview] = expectF6('POST', 'correo-masivo/preview-destinatarios', 200, ['tipo' => 'activos']);
            checkF5($preview['total'] > 0, 'Local campaign fixture has no recipients.');
            expectF6('POST', 'correo-masivo/enviar', 201, ['tipo' => 'activos', 'asunto' => 'Prueba local',
                'mensaje' => 'Transporte de prueba', 'preview_token' => $preview['preview_token'], 'request_key' => uuidF5()]);
            loginF6('cliente@example.test');
            [$portfolio] = expectF6('GET', 'mi-cartera');
            checkF5($portfolio['ventas'] === [], 'Expected an empty local portfolio.');
        }
        echo "F6-A02/A17: $target, $checks assertions.\n";
        exit(0);
    }

    [$landing] = expectF6('GET', 'landing');
    checkF5(isset($landing['empresa']['nombre'], $landing['ultimas_propiedades'], $landing['asesores']), 'Public landing projection missing.');
    checkF5(! array_key_exists('correo', $landing['asesores'][0] ?? []) && ! array_key_exists('user_id', $landing['asesores'][0] ?? []), 'Private data in public landing.');
    loginF6();
    [$users] = expectF6('GET', 'reportes/destinatarios');
    $operator = array_values(array_filter($users['data'], fn ($user) => $user['correo'] === 'operator@example.test'))[0];
    checkF5(! in_array('adviser-f5@example.test', array_column($users['data'], 'correo'), true), 'Inactive user returned by report directory.');
    expectF6('POST', 'reportes/enviar-informe', 422, ['reporte' => 'inventario-propiedades', 'usuario_ids' => [2147483647]]);
    $dates = ['desde' => '2026-11-01', 'hasta' => '2026-11-30'];
    $query = '?'.http_build_query($dates);
    $types = ['ventas-cobros', 'cartera-mora', 'comisiones', 'desempeno-asesores', 'inventario-propiedades'];
    $totals = [
        'ventas-cobros' => ['Total Ventas (Bs.)', 'total_ventas_monto', 'DETALLE DE VENTAS', 'notas', 'monto_total', 6],
        'cartera-mora' => ['Cartera Total Activa (Bs.)', 'cartera_total', 'DETALLE DE CUOTAS VENCIDAS', 'cuotas', 'monto_cuota', 6],
        'comisiones' => ['Pagado Bs.', 'pagado_bs', 'DETALLE DE COMISIONES', 'egresos', 'monto', 6],
        'desempeno-asesores' => ['Total Vendido (Bs.)', 'total_vendido', 'RANKING DE ASESORES', 'ranking', 'monto_vendido', 3],
        'inventario-propiedades' => ['Total Propiedades', 'total', 'DETALLE DEL INVENTARIO', 'propiedades', 'precio_venta', 11],
    ];
    $artifacts = realpath(getenv('MULTILIDER_F6_REPORTS_DIR') ?: '.');
    checkF5($artifacts !== false && dirname($artifacts) === realpath(__DIR__.'/../../storage/framework/testing')
        && preg_match('/^f6-reports-[a-f0-9]{32}$/D', basename($artifacts)) === 1, 'Unsafe report evidence directory.');
    $reports = [];
    foreach ($types as $type) {
        [$reports[$type]] = expectF6('GET', "reportes/$type$query");
        [, $pdf] = expectF6('GET', "reportes/$type/pdf$query");
        [, $excel] = expectF6('GET', "reportes/$type/excel$query");
        checkF5(str_starts_with($pdf, '%PDF-') && str_starts_with($excel, "PK\x03\x04"), "Invalid export for $type.");
        file_put_contents("$artifacts/$type.pdf", $pdf);
        $workbook = "$artifacts/$type.xlsx";
        file_put_contents($workbook, $excel);
        [$label, $key, $heading, $detail, $amount, $column] = $totals[$type];
        $details = $reports[$type][$detail]['data'] ?? $reports[$type][$detail];
        verifyReportWorkbook($workbook, $label, (float) $reports[$type]['kpis'][$key], $heading, array_column($details, $amount), $column);
        [$sent] = expectF6('POST', 'reportes/enviar-informe', 200, ['reporte' => $type, 'usuario_ids' => [$operator['id']], 'params' => $dates]);
        checkF5($sent['enviados'] === 1 && $sent['fallidos'] === 0, "Simulated report mail failed: $type.");
    }
    [$dashboard] = expectF6('GET', "dashboard/admin$query");
    checkF5($dashboard['kpis']['ventas_monto'] === $reports['ventas-cobros']['kpis']['total_ventas_monto'], 'Sales totals differ.');
    checkF5($dashboard['kpis']['cobros_monto'] === $reports['ventas-cobros']['kpis']['total_cobrado'], 'Payment totals differ.');
    checkF5((float) $reports['comisiones']['kpis']['pagado_bs'] === 600.0, 'Expected BOB commission missing.');
    checkF5($reports['desempeno-asesores']['kpis']['total_vendido'] === $dashboard['kpis']['ventas_monto'],
        'Adviser performance differs from confirmed sales.');
    if (getenv('MULTILIDER_F6_UPGRADE_FIXTURE') === '1') {
        [$arrears] = expectF6('GET', 'reportes/cartera-mora?asesor_id=99&desde=2024-10-01&hasta=2024-12-31');
        checkF5($arrears['kpis']['cuotas_vencidas_count'] === 1 && $arrears['kpis']['cartera_total'] === 447.89
            && $arrears['cuotas']['data'][0]['fecha_vencimiento'] === '2024-12-31'
            && $arrears['kpis']['monto_vencido'] === 452.28, 'Historical replacement or outstanding balances differ.');
    }
    [$catalog] = expectF6('GET', 'propiedades');
    $property = $catalog['data'][0];
    [$inventory] = expectF6('GET', 'reportes/inventario-propiedades?'.http_build_query([
        'estado' => $property['estado'], 'tipo' => $property['tipo'], 'moneda' => $property['moneda'],
        'precio_min' => $property['precio_venta'], 'precio_max' => $property['precio_venta'], 'sector_urbano_id' => $property['sector_urbano_id'],
    ]));
    checkF5($inventory['propiedades']['data'][0]['codigo'] === $property['codigo'], 'Inventory filters differ from the catalog.');
    [$usd] = expectF6('GET', "dashboard/admin$query&moneda=USD");
    checkF5($usd['moneda'] === 'USD' && (float) $usd['kpis']['ventas_monto'] === 0.0, 'BOB totals were mixed into USD.');
    [$group] = expectF6('GET', 'correo-masivo/grupos');
    [$preview] = expectF6('POST', 'correo-masivo/preview-destinatarios', 200, ['tipo' => 'todos']);
    checkF5($group['grupos'][0]['total'] === $preview['total'] && $preview['total'] > 0, 'Campaign preview does not match the group count.');
    [$manual] = expectF6('POST', 'correo-masivo/preview-destinatarios', 200,
        ['tipo' => 'seleccionados', 'cliente_ids' => [$preview['destinatarios'][0]['id']]]);
    checkF5($manual['total'] === 1, 'Manual campaign did not resolve exactly one client.');
    expectF6('POST', 'correo-masivo/preview-destinatarios', 422, ['tipo' => 'seleccionados', 'cliente_ids' => [2147483647]]);
    expectF6('POST', 'correo-masivo/preview-destinatarios', 422, ['tipo' => 'ciudad', 'ciudad_id' => 2147483647]);
    [$byCity] = expectF6('POST', 'correo-masivo/preview-destinatarios', 200,
        ['tipo' => 'ciudad', 'ciudad_id' => $group['ciudades'][0]['id']]);
    checkF5(in_array('client-f5-updated@example.test', array_column($byCity['destinatarios'], 'correo'), true), 'City group missed the customer’s confirmed sale.');
    $campaign = ['tipo' => 'todos', 'asunto' => 'Prueba de campaña', 'mensaje' => 'Transporte simulado.',
        'preview_token' => $preview['preview_token'], 'request_key' => uuidF5()];
    expectF6('POST', 'correo-masivo/enviar', 409, array_replace($campaign, ['preview_token' => str_repeat('0', 64)]));
    [$sent] = expectF6('POST', 'correo-masivo/enviar', 201, $campaign);
    [$again] = expectF6('POST', 'correo-masivo/enviar', 200, $campaign);
    checkF5($sent['campana_id'] === $again['campana_id'], 'Retry created a duplicate campaign.');
    [$progress] = expectF6('GET', 'correo-masivo/estado/'.$sent['campana_id']);
    checkF5($progress['total_enviados'] === $preview['total'] && $progress['progreso'] === 100, 'Campaign counts did not reconcile.');

    $adviserId = uuidF5();
    [$status] = callF5('POST', 'asesores', ['operacion_id' => $adviserId, 'command_id' => uuidF5(),
        'nombre_completo' => 'Asesor aislado F6', 'correo' => 'adviser-f6@example.test',
        'password' => 'Fixture-Strong-Adviser-42!', 'porcentaje_comision' => 3, 'estado' => true]);
    checkF5(in_array($status, [200, 201, 202], true), 'Adviser fixture rejected: '.$status);
    $adviser = waitF5($adviserId);
    checkF5($adviser['state'] === 'Completada', 'Adviser fixture was not linked.');
    expectF6('POST', 'reportes/enviar-informe', 200, ['reporte' => 'inventario-propiedades', 'asesor_ids' => [$adviser['resource_id']]]);
    $otherId = uuidF5();
    [$status] = callF5('POST', 'clientes', ['operacion_id' => $otherId, 'command_id' => uuidF5(),
        'ci' => 'F6-CLIENT-OTHER', 'lugar_expedicion' => 'LP', 'nombre_completo' => 'Otro cliente F6',
        'correo' => 'other-f6@example.test', 'estado' => true]);
    checkF5(in_array($status, [200, 201, 202], true) && waitF5($otherId)['state'] === 'Completada', 'Second customer fixture was not linked.');
    loginF6('adviser-f6@example.test', 'Fixture-Strong-Adviser-42!');
    [$own] = expectF6('GET', "dashboard/asesor$query");
    checkF5($own['kpis']['ventas_cantidad'] === 0 && $own['asesor']['id'] === $adviser['resource_id'], 'Adviser saw another adviser’s sales.');

    loginF6('sales-only@example.test');
    expectF6('GET', 'dashboard/admin', 403);
    expectF6('GET', 'correo-masivo/grupos', 403);
    foreach ($types as $type) {
        foreach (['', '/pdf', '/excel'] as $format) {
            expectF6('GET', "reportes/$type$format", 403);
        }
    }
    expectF6('POST', 'reportes/enviar-informe', 403, ['reporte' => $types[0], 'usuario_ids' => [$operator['id']]]);
    loginF6('client-f5-updated@example.test', 'F5-CLIENT-NEW');
    [$portfolio] = expectF6('GET', 'mi-cartera');
    checkF5(count($portfolio['ventas']) === 1 && $portfolio['ventas'][0]['cliente_id'] === $portfolio['cliente']['id'], 'Wrong customer portfolio.');
    $payment = $portfolio['ventas'][0]['pagos'][0]['id'];
    [, $receipt] = expectF6('GET', "mi-cartera/pagos/$payment/comprobante");
    checkF5(str_starts_with($receipt, '%PDF-'), 'Customer receipt was not rendered.');
    expectF6('GET', 'mi-cartera/pagos/2147483647/comprobante', 404);
    loginF6('other-f6@example.test', 'F6-CLIENT-OTHER');
    [$other] = expectF6('GET', 'mi-cartera');
    checkF5($other['ventas'] === [], 'Second customer can see another customer’s portfolio.');
    expectF6('GET', "mi-cartera/pagos/$payment/comprobante", 404);
    localF6();
    expectF6('GET', 'correo-masivo/estado/'.$sent['campana_id'], 404);
    echo "F6 real API and simulated email: $checks assertions.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'F6: '.$error->getMessage()."\n");
    exit(1);
}

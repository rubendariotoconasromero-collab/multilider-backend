<?php

use App\DataMigration\Connections;

define('F5_HTTP_HELPERS_ONLY', true);
require __DIR__.'/PhaseFiveIntegrationSmoke.php';

function loginF7(string $name = 'operator@example.test'): array
{
    global $token;
    $token = null;
    [$status, $data] = callF5('POST', 'login', ['correo' => $name, 'password' => 'Test-only-Password-42!']);
    checkF5($status === 200 && isset($data['access_token']), "F7 fixture login returned $status.");
    $token = $data['access_token'];

    return $data['user'];
}

function getF7(string $path, int $expected = 200): array
{
    [$status, $body] = callF5('GET', $path);
    checkF5($status === $expected, "$path returned $status instead of $expected.");

    return $body ?? [];
}

function fileF7(string $path, int $status = 200): string
{
    global $client, $token;
    $reply = $client->get('/api/'.$path, ['headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token]]);
    checkF5($reply->getStatusCode() === $status, "$path returned {$reply->getStatusCode()}.");

    return (string) $reply->getBody();
}

function clientF7(string $setting): GuzzleHttp\Client
{
    $url = getenv($setting);
    checkF5(is_string($url) && preg_match('~^http://127\.0\.0\.1:\d+$~D', $url) === 1, 'Unexpected HTTP fixture address.');

    return new GuzzleHttp\Client(['base_uri' => $url, 'http_errors' => false, 'timeout' => 12]);
}

function mappedF7(PDO $db, string $entity, int $id): int
{
    $query = $db->prepare("SELECT locator FROM f7_import_rows WHERE dataset='isolated-f7' AND entity=? AND source_key=? AND removed=0");
    $query->execute([$entity, (string) $id]);
    $row = json_decode($query->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);

    return (int) reset($row);
}

function metricsF7(): array
{
    $period = '?desde=2026-10-01&hasta=2026-11-30';
    $outputs = [];
    foreach (['BOB', 'USD'] as $currency) {
        $query = $period.'&moneda='.$currency;
        $report = getF7('reportes/ventas-cobros'.$query);
        $dashboard = getF7('dashboard/admin'.$query);
        $performance = getF7('reportes/desempeno-asesores'.$query);
        $outputs[$currency] = [$report['kpis']['total_ventas_monto'], $report['kpis']['total_cobrado'],
            $dashboard['kpis']['ventas_monto'], $dashboard['kpis']['cobros_monto'], $performance['kpis']['total_vendido']];
    }
    $mora = getF7('reportes/cartera-mora?moneda=BOB');
    $commission = getF7('reportes/comisiones'.$period);
    $inventory = getF7('reportes/inventario-propiedades');
    $outputs['cartera'] = $mora['kpis']['cartera_total'];
    $outputs['comisiones'] = [(float) $commission['kpis']['pendiente_bs'], (float) $commission['kpis']['pendiente_usd']];
    $outputs['inventario'] = $inventory['kpis']['total'];
    foreach (['ventas-cobros', 'cartera-mora', 'comisiones', 'desempeno-asesores', 'inventario-propiedades'] as $kind) {
        $query = $kind === 'inventario-propiedades' ? '' : $period;
        checkF5(str_starts_with(fileF7("reportes/$kind/pdf$query"), '%PDF-'), "PDF inválido para $kind.");
        checkF5(str_starts_with(fileF7("reportes/$kind/excel$query"), "PK\x03\x04"), "Excel inválido para $kind.");
    }

    return $outputs;
}

try {
    $remote = $client;
    $client = clientF7('PHASE5_API_LOCAL');
    if (in_array($argv[1] ?? '', ['real-public', 'real-local-only'], true)) {
        $localCompany = getF7('empresa/datos')['empresa'];
        $localProperties = getF7('landing/propiedades')['data'];
        checkF5(is_array($localCompany) && is_array($localProperties), 'Restored Laravel public data is incomplete.');
        if ($argv[1] === 'real-public') {
            $client = clientF7('PHASE5_API');
            $remoteCompany = getF7('empresa/datos')['empresa'];
            $remoteProperties = getF7('landing/propiedades')['data'];
            $projection = fn ($rows) => collect($rows)->map(fn ($row) => [
                $row['codigo'], $row['moneda'], $row['estado'], (float) $row['precio_venta'],
            ])->sortBy(fn ($row) => $row[0])->values()->all();
            checkF5($localCompany === $remoteCompany && $projection($localProperties) === $projection($remoteProperties),
                'Restored public company or catalog differs between modes.');
            checkF5(getF7('landing')['empresa'] === $localCompany, 'The restored landing lost its company data.');
        }
        echo "F7 restored real-copy {$argv[1]} passed $checks public checks.\n";
        exit(0);
    }
    $localUser = loginF7();
    $local = metricsF7();
    checkF5($local['BOB'] === [200, 15, 200, 15, 200] && $local['USD'] === [200, 0, 200, 0, 200]
        && $local['cartera'] === 195 && $local['inventario'] === 2, 'Local origin differs from its fixture controls.');
    $company = getF7('empresa/datos')['empresa']['nombre'];
    getF7('correo-masivo/grupos');
    $pdf = fileF7('contratos/1/descargar');
    $act = fileF7('entregas/1/descargar-acta');
    loginF7('cliente@example.test');
    checkF5(count(getF7('mi-cartera')['ventas']) === 2, 'Local portfolio is incomplete.');
    if (($argv[1] ?? '') === 'local-only') {
        echo "F7-A13: Laravel with four services stopped passed $checks assertions.\n";
        exit(0);
    }

    $client = $remote;
    $remoteUser = loginF7();
    checkF5($remoteUser['id'] !== $localUser['id'], 'The test did not exercise identity remapping.');
    $remoteMetrics = metricsF7();
    checkF5($remoteMetrics === $local, 'The five reports or dashboard differ across modes.');
    checkF5(getF7('empresa/datos')['empresa']['nombre'] === $company, 'Empresa Laravel changed in remote mode.');
    checkF5(getF7('landing')['empresa']['nombre'] === $company, 'Landing lost Laravel branding data.');
    getF7('correo-masivo/grupos');
    [$previewStatus, $preview] = callF5('POST', 'correo-masivo/preview-destinatarios', ['tipo' => 'activos']);
    checkF5($previewStatus === 200 && $preview['total'] > 0, 'The migrated campaign audience is unavailable.');
    [$sentStatus, $campaign] = callF5('POST', 'correo-masivo/enviar', [
        'tipo' => 'activos', 'asunto' => 'Ensayo F7', 'mensaje' => 'Transporte de prueba.',
        'preview_token' => $preview['preview_token'], 'request_key' => uuidF5(),
    ]);
    checkF5($sentStatus === 201 && getF7('correo-masivo/estado/'.$campaign['campana_id'])['total_enviados'] === $preview['total'],
        'The Laravel campaign did not preserve the migrated recipient count.');
    $operatorToken = $token;
    $client = clientF7('PHASE5_API_LOCAL');
    loginF7();
    getF7('correo-masivo/estado/'.$campaign['campana_id'], 404);
    $client = $remote;
    $token = $operatorToken;
    $targets = Connections::targets();
    $credit = mappedF7($targets['comercial'], 'notas_ventas', 1);
    $cash = mappedF7($targets['comercial'], 'notas_ventas', 2);
    $contract = mappedF7($targets['comercial'], 'contratos', 1);
    $delivery = mappedF7($targets['comercial'], 'entregas', 1);
    $adviser = mappedF7($targets['comercial'], 'asesores', 1);
    $cashProperty = mappedF7($targets['propiedades'], 'propiedades', 2);
    $method = mappedF7($targets['finanzas'], 'metodos_pago', 1);
    $bank = mappedF7($targets['finanzas'], 'cuentas_bancarias', 1);
    checkF5(hash('sha256', fileF7("contratos/$contract/descargar")) === hash('sha256', $pdf)
        && hash('sha256', fileF7("entregas/$delivery/descargar-acta")) === hash('sha256', $act), 'Private documents changed during import.');
    checkF5(str_starts_with(fileF7("asesores/$adviser/foto"), "\x89PNG"), 'Adviser photo missing.');
    $schedule = getF7("ventas/$credit/plan-pago");
    $plan = $schedule['plan_pago'];
    $quota = $plan['cuotas'][0]['id'];
    checkF5(count($plan['cuotas']) === 3 && (float) $schedule['resumen']['saldo_capital'] === 195.0
        && $plan['cuotas'][1]['estado'] === 'Reemplazada', 'Historical revision or partial balance changed.');
    loginF7('cliente@example.test');
    $portfolio = getF7('mi-cartera');
    checkF5(count($portfolio['ventas']) === 2 && array_sum(array_map(fn ($sale) => count($sale['pagos']), $portfolio['ventas'])) === 1,
        'Remote portfolio did not follow the identity mapping.');
    fileF7("contratos/$contract/descargar", 403);
    $token = null;
    [$status] = callF5('POST', 'login', ['correo' => 'sales-only@example.test', 'password' => 'Test-only-Password-42!']);
    checkF5(in_array($status, [401, 403], true), 'The source deactivation did not reach Seguridad.');
    $token = $operatorToken;

    $command = fn () => ['operacion_id' => uuidF5(), 'command_id' => uuidF5()];
    $payment = $command() + ['nota_venta_id' => $credit, 'cuota_id' => $quota, 'monto' => 5,
        'metodo_pago_id' => $method, 'cuenta_id' => $bank, 'fecha_pago' => '2026-11-06'];
    [$status, $result] = callF5('POST', 'pagos', $payment);
    checkF5($status === 200, "Imported account rejected payment: $status.");
    $newPayment = $result['data']['id'];
    [$status] = callF5('PUT', "pagos/$newPayment/cancelar", $command());
    checkF5($status === 200, "Imported account rejected reversal: $status.");
    $planId = $plan['id'];
    [$status] = callF5('POST', "planes-pago/$planId/amortizar", $command() + ['monto' => 10, 'metodo_pago_id' => $method,
        'cuenta_id' => $bank, 'fecha_pago' => '2026-11-07']);
    checkF5($status === 200, "Imported plan rejected amortization: $status.");
    [$status] = callF5('POST', "planes-pago/$planId/reprogramar", $command() + ['motivo' => 'Ensayo F7', 'fecha_reprogramacion' => '2026-11-08',
        'cuota_desde' => 1, 'nueva_tasa_interes' => 10, 'nuevo_numero_cuotas' => 2, 'nueva_fecha_inicio' => '2026-12-01']);
    checkF5($status === 200, "Imported plan rejected rescheduling: $status.");
    $cancel = $command();
    [$status] = callF5('PUT', "ventas/$cash/anular", $cancel);
    checkF5($status === 202 && waitF5($cancel['operacion_id'])['state'] === 'Completada', 'Imported sale did not cancel through its historical link.');
    checkF5(getF7("propiedades/$cashProperty")['estado'] === 'Disponible', 'Cancellation did not release the imported generation.');
    checkF5(getF7('empresa/datos')['pagos_prueba_disponibles'] === false, 'External test payments were exposed by the fixture.');
    echo "F7 acceptance passed $checks assertions across both modes.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'F7 acceptance: '.$error->getMessage().PHP_EOL);
    exit(1);
}

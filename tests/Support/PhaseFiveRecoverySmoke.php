<?php
define('F5_HTTP_HELPERS_ONLY', true);
require __DIR__.'/PhaseFiveIntegrationSmoke.php';
if (getenv('MULTILIDER_TEST_ISOLATED') !== '1' || getenv('DB_DATABASE') !== 'multilider_tests_isolated') { throw new RuntimeException('Isolated runner required.'); }
foreach (['PHASE5_API', 'PHASE5_API_LOCAL', 'PHASE5_COMMERCIAL'] as $name) {
    $parts = parse_url(getenv($name));
    if (($parts['host'] ?? '') !== '127.0.0.1' || ($parts['port'] ?? 0) < 1024) { throw new RuntimeException('Fixture endpoint required.'); }
}
$state = __DIR__.'/../../storage/framework/testing/f5-recovery-state.json';
$action = $argv[1] ?? ''; $role = $argv[2] ?? '';
if (! in_array($role, ['seguridad', 'propiedades', 'comercial', 'finanzas', 'laravel'], true)) { throw new RuntimeException('Invalid fixture role.'); }
try {
    if ($action === 'prepare') {
        [$status, $session] = callF5('POST', 'login', ['correo' => 'operator@example.test', 'password' => 'Test-only-Password-42!']);
        checkF5($status === 200, 'Fixture login failed.'); $token = $session['access_token'];
        [$status, $properties] = callF5('GET', 'propiedades'); checkF5($status === 200, 'Catalog unavailable before fault.');
        $source = $properties['data'][0]; $key = uuidF5();
        $response = $client->post('/api/propiedades', ['headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'Idempotency-Key' => $key], 'json' => [
            'propietario_ids' => array_column($source['propietarios'], 'id'), 'sector_urbano_id' => $source['sector_urbano_id'], 'tipo' => 'Lote',
            'precio_venta' => '23000.00', 'moneda' => 'BOB', 'superficie_m2' => '200.00', 'activo' => true,
        ]]);
        checkF5($response->getStatusCode() === 201, 'Recovery property fixture failed.');
        $property = json_decode((string) $response->getBody(), true)['data'];
        $sale = ['operacion_id' => uuidF5(), 'command_id' => uuidF5(), 'cliente_id' => 1, 'asesor_id' => 1,
            'propiedad_id' => $property['id'], 'expected_version' => $property['version'], 'fecha' => '2026-11-05',
            'tipo_venta' => 'CONTADO', 'monto_total' => 23000, 'monto_liquido' => 23000];
        $remote = $client; $tokenRemote = $token;
        $client = new GuzzleHttp\Client(['base_uri' => getenv('PHASE5_API_LOCAL'), 'http_errors' => false, 'timeout' => 8]);
        [$status, $local] = callF5('POST', 'login', ['correo' => 'operator@example.test', 'password' => 'Test-only-Password-42!']);
        checkF5($status === 200, 'Local fixture login failed.'); $client = $remote;
        file_put_contents($state, json_encode(['role' => $role, 'token' => $tokenRemote, 'local' => $local['access_token'], 'sale' => $sale], JSON_THROW_ON_ERROR));
        if ($role === 'laravel') { [$status] = callF5('POST', 'ventas', $sale); checkF5($status === 202, 'Sale not admitted before Laravel outage.'); }
        exit(0);
    }
    $data = json_decode(file_get_contents($state), true, 32, JSON_THROW_ON_ERROR);
    checkF5($data['role'] === $role, 'Recovery state mismatch.');
    $token = $data['token']; $sale = $data['sale']; $id = $sale['operacion_id'];
    if ($action === 'offline') {
        if ($role === 'laravel') {
            try { $client->get('/api/me', ['timeout' => 2]); throw new RuntimeException('Laravel should be stopped.'); }
            catch (GuzzleHttp\Exception\ConnectException) { }
            $client = new GuzzleHttp\Client(['base_uri' => getenv('PHASE5_COMMERCIAL'), 'http_errors' => false, 'timeout' => 8]);
            checkF5(waitF5($id)['state'] === 'Completada', 'Saga could not finish while Laravel was stopped.');
        } else {
            [$status] = callF5('POST', 'ventas', $sale);
            checkF5($status === (in_array($role, ['propiedades', 'finanzas'], true) ? 202 : 503), 'Unexpected admission while service stopped: '.$status);
            if ($status === 202) {
                $pending = null;
                for ($n = 0; $n < 40; $n++) {
                    [$code, $pending] = callF5('GET', 'operaciones/'.$id);
                    if ($code === 200 && ($pending['error_code'] ?? null)) { break; }
                    usleep(150000);
                }
                checkF5(($pending['state'] ?? '') === 'Pendiente' && ($pending['error_code'] ?? '') === 'RESULTADO_INCIERTO', 'Failed operation must stay recoverable.');
            }
        }
        $client = new GuzzleHttp\Client(['base_uri' => getenv('PHASE5_API_LOCAL'), 'http_errors' => false, 'timeout' => 8]);
        $token = $data['local']; [$status] = callF5('GET', 'comercial/opciones');
        checkF5($status === 200, 'Local sales selectors must work during outage.');
        echo "F5 confirmed real outage of $role; local mode remains independent.\n";
    } elseif ($action === 'recover') {
        if (in_array($role, ['seguridad', 'comercial'], true)) { [$status] = callF5('POST', 'ventas', $sale); checkF5($status === 202, 'Same UUID not admitted after restart.'); }
        $op = waitF5($id); checkF5($op['state'] === 'Completada', 'Recovery did not complete.');
        [$status, $property] = callF5('GET', 'propiedades/'.$sale['propiedad_id']);
        checkF5($status === 200 && $property['estado'] === 'Vendido', 'Recovered sale did not confirm its own property.');
        [$status, $sales] = callF5('GET', 'ventas?tipo_venta=CONTADO&per_page=100');
        checkF5($status === 200 && count(array_filter($sales['data'], fn ($row) => $row['propiedad_id'] === $sale['propiedad_id'])) === 1, 'Recovery duplicated or omitted sale.');
        unlink($state); echo "F5 recovered the same operation after restarting $role.\n";
    } else { throw new RuntimeException('Unknown recovery action.'); }
} catch (Throwable $error) { fwrite(STDERR, 'F5 recovery: '.$error->getMessage().PHP_EOL); exit(1); }

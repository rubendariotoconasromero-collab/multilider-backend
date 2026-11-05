<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;

define('F5_HTTP_HELPERS_ONLY', true);
require __DIR__.'/PhaseFiveIntegrationSmoke.php';

function endpointF5(string $name): Client
{
    global $transport;
    $url = getenv($name);
    if (! is_string($url) || ! preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{3,4}$~D', $url)) {
        throw new RuntimeException('Only loopback test listeners are allowed.');
    }

    return new Client(['base_uri' => $url, 'handler' => HandlerStack::create($transport),
        'http_errors' => false, 'allow_redirects' => false, 'timeout' => 20]);
}

function identityF5(): PDO
{
    $port = (int) getenv('MULTILIDER_TEST_PORT');
    $database = getenv('MULTILIDER_IDENTITY_DATABASE');
    if ($port < 1024 || $port === 3306 || $database !== 'multilider_identity_tests_isolated') {
        throw new RuntimeException('Unsafe acceptance database.');
    }

    return new PDO("mysql:host=127.0.0.1;port=$port;dbname=$database", getenv('MULTILIDER_IDENTITY_USER'), getenv('MULTILIDER_IDENTITY_PASSWORD'));
}

function queryF5(PDO $db, string $sql, array $params): mixed
{
    $query = $db->prepare($sql);
    $query->execute($params);

    return $query->fetchColumn();
}

function loginF5(): string
{
    [$status, $body] = callF5('POST', 'login', ['correo' => 'operator@example.test', 'password' => 'Test-only-Password-42!']);
    checkF5($status === 200, 'Acceptance login failed.');

    return $body['access_token'];
}

function operationF5(): array
{
    return ['operacion_id' => uuidF5(), 'command_id' => uuidF5()];
}

function saleF5(string $price = '10000.00', string $currency = 'BOB'): array
{
    [$status, $catalog] = callF5('GET', 'propiedades');
    checkF5($status === 200 && isset($catalog['data'][0]), 'Acceptance catalog unavailable.');
    $source = $catalog['data'][0];
    [$status, $created] = callF5('POST', 'propiedades', operationF5() + [
        'propietario_ids' => array_column($source['propietarios'], 'id'),
        'sector_urbano_id' => $source['sector_urbano_id'], 'tipo' => 'Lote',
        'precio_venta' => $price, 'moneda' => $currency, 'superficie_m2' => '200.00', 'activo' => true,
    ]);
    checkF5($status === 201, 'Acceptance property creation failed: '.$status);
    $property = $created['data'];

    return operationF5() + ['cliente_id' => 1, 'asesor_id' => 1, 'propiedad_id' => $property['id'],
        'expected_version' => $property['version'] ?? null, 'fecha' => '2026-11-04',
        'tipo_venta' => 'CONTADO', 'monto_total' => $price, 'monto_liquido' => $price];
}

function payF5(int $sale): array
{
    return operationF5() + ['nota_venta_id' => $sale, 'concepto_pago' => 'VENTA_CONTADO',
        'monto' => 100, 'fecha_pago' => '2026-11-04'];
}

function completedSaleF5(array $payload): int
{
    [$status] = callF5('POST', 'ventas', $payload);
    checkF5($status === 202, 'Remote sale was not admitted.');
    $row = waitF5($payload['operacion_id']);
    checkF5($row['state'] === 'Completada', 'Remote sale did not complete.');

    return $row['venta_id'];
}

function propertyStateF5(int $id): string
{
    [$status, $body] = callF5('GET', "propiedades/$id");
    checkF5($status === 200, 'Acceptance property unavailable.');

    return $body['estado'];
}

function checkpointF5(string $id, string $step, string $side): string
{
    $directory = realpath(getenv('MULTILIDER_F5_BOUNDARY_DIR'));
    $testRoot = realpath(__DIR__.'/../../storage/framework/testing');
    if (! $directory || dirname($directory) !== $testRoot || ! preg_match('/^f5-boundary-[a-f0-9]{32}$/D', basename($directory))) {
        throw new RuntimeException('Unsafe boundary fixture.');
    }
    $path = $directory.DIRECTORY_SEPARATOR.str_replace('-', '', $id)."-$step-$side";
    file_put_contents($path.'.arm', '');

    return $path;
}

function eventuallyF5(callable $probe, string $message): mixed
{
    $deadline = microtime(true) + 18;
    do {
        $value = $probe();
        if ($value) { return $value; }
        usleep(50_000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException($message);
}

function hitF5(string $path): void
{
    eventuallyF5(fn () => is_file($path.'.hit'), 'Boundary not reached: '.basename($path));
}

function asyncF5(Client $endpoint, string $token, string $method, string $path, array $payload): \GuzzleHttp\Promise\PromiseInterface
{
    return $endpoint->requestAsync($method, '/api/'.$path, [
        'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'Idempotency-Key' => $payload['command_id']],
        'json' => $payload,
    ]);
}

function expiredF5(string $jwt): string
{
    [$header, $encoded] = explode('.', $jwt);
    $decode = static fn ($value) => base64_decode(strtr($value, '-_', '+/'));
    $encode = static fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $claims = json_decode($decode($encoded), true, 32, JSON_THROW_ON_ERROR);
    foreach (['iat', 'nbf', 'exp'] as $field) {
        if (isset($claims[$field])) { $claims[$field] = time() - 3600; }
    }
    $input = $header.'.'.$encode(json_encode($claims, JSON_THROW_ON_ERROR));

    return $input.'.'.$encode(hash_hmac('sha256', $input, getenv('Jwt__Key'), true));
}

function paymentsF5(int $id): array
{
    [$status, $body] = callF5('GET', 'pagos?nota_venta_id='.$id.'&per_page=100');
    checkF5($status === 200, 'Acceptance payments read failed.');

    return array_values(array_filter($body['data'], fn ($row) => $row['nota_venta_id'] === $id && $row['estado'] === 'PAGADO'));
}

if (defined('F5_ACCEPTANCE_HELPERS_ONLY')) { return; }

try {
    $transport = new CurlMultiHandler(['select_timeout' => 0.1]);
    $client = $remote = endpointF5('PHASE5_API');
    $identity = identityF5();
    $token = loginF5();
    $fresh = $token;
    $invalid = saleF5();
    $token = expiredF5($fresh);
    [$status] = callF5('POST', 'ventas', $invalid);
    checkF5($status === 401 && ! queryF5($identity, 'SELECT COUNT(*) FROM operation_grants WHERE Id = ?', [$invalid['operacion_id']]), 'Expired JWT created an authorization.');
    $token = $fresh;
    [$status] = callF5('GET', 'operaciones/'.$invalid['operacion_id']);
    checkF5($status === 404, 'Expired JWT created a commercial intention.');
    echo "F5-A06: expired signed JWT rejected before creating grant or sale.\n";

    foreach (['before', 'after'] as $side) {
        $token = loginF5();
        $sale = saleF5();
        $id = $sale['operacion_id'];
        $boundary = checkpointF5($id, 'AutorizarConfirmacion', $side);
        [$status] = callF5('POST', 'ventas', $sale);
        checkF5($status === 202, 'Could not admit revocation fixture.');
        try {
            hitF5($boundary);
            $expected = $side === 'before' ? 'Admitida' : 'Confirmando';
            checkF5(queryF5($identity, 'SELECT State FROM operation_grants WHERE Id = ?', [$id]) === $expected, 'Unexpected durable grant at boundary.');
            $revoked = $token;
            [$status] = callF5('POST', 'logout');
            checkF5($status === 200, 'Could not revoke actor session.');
            $other = $sale;
            $other['operacion_id'] = uuidF5();
            $other['command_id'] = uuidF5();
            $token = $revoked;
            [$status] = callF5('POST', 'ventas', $other);
            checkF5($status === 401 && ! queryF5($identity, 'SELECT COUNT(*) FROM operation_grants WHERE Id = ?', [$other['operacion_id']]), 'Revoked actor admitted a new intention.');
        } finally {
            file_put_contents($boundary.'.release', '');
        }
        $token = loginF5();
        if ($side === 'before') {
            $pending = eventuallyF5(function () use ($id) {
                [$status, $row] = callF5('GET', 'operaciones/'.$id);
                return $status === 200 && ($row['error_code'] ?? null) === 'REAUTHORIZATION_REQUIRED' ? $row : null;
            }, 'No reauthorization requirement after revocation before pivot.');
            checkF5($pending['state'] === 'Pendiente' && propertyStateF5($sale['propiedad_id']) !== 'Vendido'
                && queryF5($identity, 'SELECT State FROM operation_grants WHERE Id = ?', [$id]) === 'Admitida', 'Revoked actor confirmed a sale before the pivot.');
            // Reauthorization must reuse the same UUID, with the original economic data.
            [$status] = callF5('POST', 'ventas', $sale);
            checkF5($status === 202 && waitF5($id)['state'] === 'Completada', 'Same intention did not recover with new session.');
        } else {
            checkF5(waitF5($id)['state'] === 'Completada' && propertyStateF5($sale['propiedad_id']) === 'Vendido', 'Revocation interrupted an irreversible decision.');
        }
        echo ($side === 'before' ? 'F5-A07' : 'F5-A08').": real session revocation $side the durable pivot verified.\n";
    }

    // A real identity commits in Seguridad. Comercial receives a fixture connection
    // failure instead of the successful receipt and must recover without the password.
    $profile = operationF5() + ['nombre_completo' => 'Asesor aceptación F5', 'correo' => 'accept-adviser@example.test',
        'estado' => true, 'porcentaje_comision' => 2, 'password' => 'Fixture-Only-Adviser-42!'];
    $id = $profile['operacion_id'];
    $boundary = checkpointF5($id, 'ProvisionarIdentidad', 'after');
    $request = asyncF5($remote, $token, 'POST', 'asesores', $profile);
    try {
        eventuallyF5(function () use ($request, $boundary, $transport) {
            // Pump the socket while the API request is paused inside Comercial.
            $transport->tick();
            return is_file($boundary.'.hit') || $request->getState() !== 'pending';
        }, 'Adviser request did not reach identity boundary.');
        hitF5($boundary);
        $userId = queryF5($identity, 'SELECT UserId FROM profile_identity_receipts WHERE Id = ?', [$id]);
        checkF5($userId && (int) queryF5($identity, 'SELECT estado FROM users WHERE id = ?', [$userId]) === 0, 'Unbound identity was active.');
        $security = new Client(['base_uri' => endpointF5('PHASE5_SECURITY')->getConfig('base_uri'),
            'http_errors' => false, 'allow_redirects' => false, 'timeout' => 5]);
        $early = $security->post('/api/auth/login', ['json' => ['correo' => $profile['correo'], 'password' => $profile['password']]]);
        checkF5(in_array($early->getStatusCode(), [401, 403], true), 'Unbound identity could log in.');
    } finally {
        file_put_contents($boundary.'.release', 'lose-response');
    }
    $status = $request->wait()->getStatusCode();
    checkF5($status === 202, 'Lost identity response was not recoverable: '.$status);
    $profileDone = waitF5($id);
    checkF5($profileDone['state'] === 'Completada'
        && (int) queryF5($identity, 'SELECT COUNT(*) FROM users WHERE correo = ?', [$profile['correo']]) === 1
        && (int) queryF5($identity, 'SELECT estado FROM users WHERE id = ?', [$userId]) === 1, 'Adviser replay duplicated identity or did not activate the bound user.');
    [$status, $bound] = callF5('GET', 'asesores/'.$profileDone['resource_id']);
    checkF5($status === 200 && (int) ($bound['user_id'] ?? $bound['data']['user_id'] ?? 0) === (int) $userId, 'Profile was not bound to the original receipt.');
    echo "F5-C03/C04: identity stayed disabled until binding and recovered from the committed response lost without sending the password again.\n";

    $collision = operationF5() + ['ci' => 'F5-COLLISION', 'nombre_completo' => 'Perfil colisionado',
        'correo' => 'operator@example.test', 'lugar_expedicion' => 'LP', 'estado' => true, 'user_id' => 1];
    [$status] = callF5('POST', 'clientes', $collision);
    checkF5($status === 202, 'Collision was not recorded as an intention: '.$status);
    [$status, $rejected] = callF5('GET', 'operaciones/'.$collision['operacion_id']);
    checkF5($status === 200 && $rejected['error_code'] === 'IDENTITY_CONFLICT'
        && ! queryF5($identity, 'SELECT COUNT(*) FROM profile_identity_receipts WHERE Id = ?', [$collision['operacion_id']])
        && queryF5($identity, 'SELECT correo FROM users WHERE id = 1', []) === 'operator@example.test'
        && (int) queryF5($identity, 'SELECT estado FROM users WHERE id = 1', []) === 1, 'Collision reused or modified the original operator identity.');
    $numeric = operationF5() + ['ci' => 'F5-NUMERIC', 'nombre_completo' => 'Perfil numérico',
        'correo' => 'accept-numeric@example.test', 'lugar_expedicion' => 'LP', 'estado' => true, 'user_id' => 1];
    [$status] = callF5('POST', 'clientes', $numeric);
    checkF5(in_array($status, [200, 202], true) && waitF5($numeric['operacion_id'])['state'] === 'Completada'
        && (int) queryF5($identity, 'SELECT UserId FROM profile_identity_receipts WHERE Id = ?', [$numeric['operacion_id']]) > 1, 'Numeric ID attached an unrelated identity.');
    echo "F5-C05: existing email and supplied numeric user ID cannot take over an identity.\n";

    foreach (['before', 'after'] as $side) {
        $sale = saleF5();
        [$status] = callF5('POST', 'ventas', $sale);
        checkF5($status === 202, 'Could not admit payment/cancel fixture.');
        $id = waitF5($sale['operacion_id'])['venta_id'];
        $cancel = operationF5();
        $boundary = checkpointF5($cancel['operacion_id'], 'CerrarFinanzas', $side);
        [$status] = callF5('PUT', "ventas/$id/anular", $cancel);
        checkF5($status === 202, 'Could not admit cancellation.');
        try {
            hitF5($boundary);
            checkF5(propertyStateF5($sale['propiedad_id']) === 'Vendido', 'Property released before confirmed financial closure.');
            $payment = payF5($id) + ['metodo_pago_id' => 1, 'cuenta_id' => 1];
            [$paid] = callF5('POST', 'pagos', $payment);
            checkF5($paid === ($side === 'before' ? 200 : 409), 'Unexpected remote writer result: '.$paid);
        } finally {
            file_put_contents($boundary.'.release', '');
        }
        $closed = waitF5($cancel['operacion_id']);
        checkF5($closed['state'] === ($side === 'before' ? 'Rechazada' : 'Completada')
            && propertyStateF5($sale['propiedad_id']) === ($side === 'before' ? 'Vendido' : 'Disponible')
            && count(paymentsF5($id)) === ($side === 'before' ? 1 : 0), 'Remote payment/close invariant failed.');
    }
    echo "F5-N03: both overlapping payment/closure orders preserve the property and recorded payment invariant.\n";

    require __DIR__.'/PhaseFiveWriterRaces.php';

    $client = $local = endpointF5('PHASE5_API_LOCAL');
    $second = endpointF5('PHASE5_API_LOCAL_SECOND');
    $token = loginF5();
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $sale = saleF5();
        [$status, $created] = callF5('POST', 'ventas', $sale);
        checkF5($status === 201, 'Local sale fixture failed: '.$status);
        $id = $created['data']['id'];
        $pay = asyncF5($local, $token, 'POST', 'pagos', payF5($id));
        $cancel = asyncF5($second, $token, 'PUT', "ventas/$id/anular", operationF5());
        $responses = Utils::unwrap([$pay, $cancel]);
        [$paid, $closed] = array_map(fn (ResponseInterface $response) => $response->getStatusCode(), $responses);
        checkF5(in_array([$paid, $closed], [[201, 409], [409, 200]], true), "Unsafe local concurrent outcome: $paid/$closed");
        checkF5(propertyStateF5($sale['propiedad_id']) === ($paid === 201 ? 'Vendido' : 'Disponible')
            && count(paymentsF5($id)) === ($paid === 201 ? 1 : 0), 'Local payment/close invariant failed.');
    }
    echo "F5-L05: concurrent requests against independent Laravel processes and the same isolated MySQL store.\n";
    echo "F5 acceptance assertions passed: $checks.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'F5 acceptance: '.$error->getMessage().PHP_EOL);
    exit(1);
}

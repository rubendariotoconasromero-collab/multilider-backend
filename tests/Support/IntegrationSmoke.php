<?php

require __DIR__.'/../../vendor/autoload.php';
set_exception_handler(function (Throwable $error): void {
    // Never include call arguments: failed HTTP calls carry test bearer credentials.
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});
if (getenv('MULTILIDER_TEST_ISOLATED') !== '1') {
    throw new RuntimeException('Isolated runner required.');
}
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Utils;

$client = new Client(['http_errors' => false, 'timeout' => 8, 'connect_timeout' => 2, 'allow_redirects' => false]);
$checks = 0;
function callApi(string $service, string $method, string $path, int $expected, array $body = [], ?string $token = null): array
{
    global $client,$checks;
    $base = getenv('PHASE3_'.$service);
    if (! str_starts_with($base, 'http://127.0.0.1:')) {
        throw new RuntimeException('Unsafe fixture endpoint.');
    }
    $options = ['headers' => ['Accept' => 'application/json']];
    if ($body) {
        $options['json'] = $body;
    }if ($token) {
        $options['headers']['Authorization'] = 'Bearer '.$token;
    }
    $response = $client->request($method, $base.$path, $options);
    if ($response->getStatusCode() !== $expected) {
        throw new RuntimeException("$service $method $path: expected $expected, received ".$response->getStatusCode());
    }
    $checks++;

    return json_decode((string) $response->getBody(), true) ?? [];
}
function login(string $service, string $email = 'operator@example.test', string $password = 'Test-only-Password-42!'): string
{
    $direct = $service === 'SECURITY';
    $data = callApi($service, 'POST', $direct ? '/api/auth/login' : '/api/login', 200, ['correo' => $email, 'password' => $password]);

    return $data[$direct ? 'accessToken' : 'access_token'];
}
function check(bool $value, string $message): void
{
    global $checks;
    if (! $value) {
        throw new RuntimeException($message);
    }$checks++;
}
$stateFile = __DIR__.'/../../storage/framework/testing/live-tokens.json';
$phase = $argv[1] ?? '';
if ($phase === 'online') {
    $local = login('API_LOCAL');
    $remote = login('API_REMOTE');
    check(str_contains($local, '|') && count(explode('.', $remote)) === 3, 'Token origins mixed.');
    callApi('API_REMOTE', 'GET', '/api/me', 401, [], $local);
    callApi('API_LOCAL', 'GET', '/api/me', 401, [], $remote);
    // Warm the SQL Server/EF model outside Laravel's strict five-second HTTP budget.
    // A cold service may legitimately return 503 at that boundary; never raise production timeouts.
    $direct = callApi('PROPERTIES', 'GET', '/api/propiedades', 200, [], $remote);
    check($direct['data'][0]['codigo'] === 'REMOTE-ONLY', 'Direct legacy API changed.');
    $page = callApi('API_REMOTE', 'GET', '/api/propiedades?search=REMOTE&per_page=1', 200, [], $remote);
    check($page['data'][0]['codigo'] === 'REMOTE-ONLY' && $page['data'][0]['precio_venta'] === '20000.00', 'Remote catalog did not use real SQL Server data.');
    $localPage = callApi('API_LOCAL', 'GET', '/api/propiedades', 200, [], $local);
    check($localPage['data'][0]['codigo'] === 'LOCAL-ONLY', 'Local catalog changed origin.');
    $timings = [];
    for ($i = 0; $i < 5; $i++) {
        $start = microtime(true);
        callApi('API_REMOTE', 'GET', '/api/propiedades', 200, [], $remote);
        $timings[] = (microtime(true) - $start) * 1000;
    }
    $me = callApi('API_REMOTE', 'PUT', '/api/perfil', 200, ['nombre' => 'Operador remoto ficticio', 'correo' => 'renamed@example.test'], $remote);
    check($me['data']['id'] === 1, 'Profile identity changed.');
    callApi('API_REMOTE', 'GET', '/api/me', 200, [], $remote);
    callApi('API_REMOTE', 'PUT', '/api/perfil', 200, ['nombre' => 'Operador remoto ficticio', 'correo' => 'operator@example.test'], $remote);
    callApi('API_REMOTE', 'PUT', '/api/perfil', 422, ['nombre' => 'bad', 'correo' => 'operator@example.test', 'id' => 2, 'estado' => true], $remote);
    $list = callApi('API_REMOTE', 'GET', '/api/usuarios', 200, [], $remote);
    check($list[0]['correo'] === 'operator@example.test', 'Admin response not normalized.');
    callApi('SECURITY', 'POST', '/api/usuario', 201, ['nombre' => 'Usuario secundario', 'correo' => 'other@example.test', 'password' => 'Test-only-Password-42!', 'estado' => true], $remote);
    $other = login('SECURITY', 'other@example.test');
    callApi('PROPERTIES', 'GET', '/api/propiedades', 403, [], $other);
    callApi('API_REMOTE', 'GET', '/api/usuarios', 403, [], $other);
    callApi('SECURITY', 'PUT', '/api/usuario/1', 403, ['estado' => false], $other);
    callApi('SECURITY', 'POST', '/api/usuario/2/asignaciones', 200, ['rolPermisoIds' => [4]], $remote);
    callApi('SECURITY', 'GET', '/api/auth/me', 401, [], $other);
    $other = login('SECURITY', 'other@example.test');
    callApi('PROPERTIES', 'GET', '/api/propiedades', 200, [], $other);
    callApi('SECURITY', 'POST', '/api/usuario/2/asignaciones', 200, ['rolPermisoIds' => []], $remote);
    callApi('PROPERTIES', 'GET', '/api/propiedades', 401, [], $other);
    $other = login('SECURITY', 'other@example.test');
    callApi('SECURITY', 'PUT', '/api/auth/password', 200, ['password_actual' => 'Test-only-Password-42!', 'password_nuevo' => 'New-test-Password-43!', 'password_nuevo_confirmation' => 'New-test-Password-43!'], $other);
    callApi('SECURITY', 'GET', '/api/auth/me', 401, [], $other);
    $other = login('SECURITY', 'other@example.test', 'New-test-Password-43!');
    callApi('SECURITY', 'DELETE', '/api/usuario/2', 200, [], $remote);
    callApi('SECURITY', 'GET', '/api/auth/me', 401, [], $other);
    callApi('SECURITY', 'DELETE', '/api/usuario/2', 200, [], $remote);
    $device = login('API_REMOTE');
    callApi('API_REMOTE', 'POST', '/api/logout', 200, [], $device);
    callApi('API_REMOTE', 'POST', '/api/logout', 200, [], $device);
    callApi('PROPERTIES', 'GET', '/api/propiedades', 401, [], $device);
    callApi('API_REMOTE', 'GET', '/api/me', 200, [], $remote);
    callApi('API_REMOTE', 'POST', '/api/ventas', 503, [], $remote);
    callApi('API_REMOTE', 'GET', '/api/mi-cartera', 503, [], $remote);

    // Real MySQL transaction race: a login must never authenticate the previous
    // password with the new version after a simultaneous password change commits.
    callApi('SECURITY', 'POST', '/api/usuario', 201, ['nombre' => 'Race fixture', 'correo' => 'race@example.test', 'password' => 'Test-only-Password-42!'], $remote);
    $race = login('SECURITY', 'race@example.test');
    $securityUrl = getenv('PHASE3_SECURITY');
    $pending = [$client->putAsync($securityUrl.'/api/auth/password', [
        'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$race],
        'json' => ['password_actual' => 'Test-only-Password-42!', 'password_nuevo' => 'Race-new-Password-43!', 'password_nuevo_confirmation' => 'Race-new-Password-43!'],
    ])];
    for ($i = 0; $i < 6; $i++) {
        $pending[] = $client->postAsync($securityUrl.'/api/auth/login', ['json' => ['correo' => 'race@example.test', 'password' => 'Test-only-Password-42!']]);
    }
    $results = Utils::settle($pending)->wait();
    foreach ($results as $index => $result) {
        check($result['state'] === 'fulfilled', 'Concurrent identity request failed.');
        $response = $result['value'];
        if ($index === 0) {
            check($response->getStatusCode() === 200, 'Concurrent password change failed.');
        } else {
            check(in_array($response->getStatusCode(), [200, 401], true), 'Concurrent login returned an unexpected status.');
            if ($response->getStatusCode() === 200) {
                $issued = json_decode((string) $response->getBody(), true)['accessToken'];
                callApi('SECURITY', 'GET', '/api/auth/me', 401, [], $issued);
            }
        }
    }
    callApi('SECURITY', 'GET', '/api/auth/me', 401, [], $race);
    login('SECURITY', 'race@example.test', 'Race-new-Password-43!');

    $concurrentTimings = [];
    $requests = function () use ($client, $remote, &$concurrentTimings) {
        for ($i = 0; $i < 12; $i++) {
            yield function () use ($client, $remote, &$concurrentTimings) {
                $started = microtime(true);

                return $client->getAsync(getenv('PHASE3_PROPERTIES').'/api/propiedades', ['headers' => ['Authorization' => 'Bearer '.$remote]])
                    ->then(function ($response) use ($started, &$concurrentTimings) {
                        check($response->getStatusCode() === 200, 'Concurrent session check failed.');
                        $concurrentTimings[] = (microtime(true) - $started) * 1000;
                    });
            };
        }
    };
    (new Pool($client, $requests(), ['concurrency' => 4,
        'rejected' => function () {
            throw new RuntimeException('Concurrent authority request did not complete.');
        },
    ]))->promise()->wait();
    check(count($concurrentTimings) === 12, 'Concurrent sample incomplete.');
    sort($concurrentTimings);
    sort($timings);
    file_put_contents(__DIR__.'/../../storage/framework/testing/integration-metrics.json', json_encode(['online_checks' => $checks, 'catalog_p95_ms' => round(end($timings), 2),
        'direct_samples' => 12, 'direct_concurrency' => 4, 'direct_p95_ms' => round($concurrentTimings[11], 2)], JSON_PRETTY_PRINT));
    file_put_contents($stateFile, json_encode(['local' => $local, 'remote' => $remote]));
} else {
    $state = json_decode(file_get_contents($stateFile), true);
    $local = $state['local'];
    $remote = $state['remote'];
    if ($phase === 'outage') {
        callApi('API_REMOTE', 'GET', '/api/me', 503, [], $remote);
        callApi('PROPERTIES', 'GET', '/api/propiedades', 503, [], $remote);
        $result = callApi('API_REMOTE', 'POST', '/api/logout', 503, [], $remote);
        check($result['code'] === 'REVOCATION_PENDING', 'Logout falsely reported global success.');
        callApi('API_REMOTE', 'GET', '/api/me', 401, [], $remote);
        callApi('API_LOCAL', 'GET', '/api/propiedades', 200, [], $local);
    } elseif ($phase === 'recovered') {
        callApi('PROPERTIES', 'GET', '/api/propiedades', 401, [], $remote);
        callApi('API_REMOTE', 'GET', '/api/me', 401, [], $remote);
        callApi('API_LOCAL', 'GET', '/api/me', 200, [], $local);
    } else {
        throw new RuntimeException('Unknown phase.');
    }
}
file_put_contents(__DIR__.'/../../storage/framework/testing/integration-'.$phase.'-result.json', json_encode([
    'phase' => $phase, 'checks' => $checks, 'passed' => true, 'finished_at_utc' => gmdate('c'),
], JSON_PRETTY_PRINT));
echo "F3 live $phase: $checks checks passed; no production connections.\n";

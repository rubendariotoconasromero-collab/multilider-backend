<?php

require __DIR__.'/../../vendor/autoload.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, 'F4 integration failed: '.$e->getMessage().PHP_EOL);
    exit(1);
});
if (getenv('MULTILIDER_TEST_ISOLATED') !== '1') {
    throw new RuntimeException('Isolated runner required.');
}
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Str;

$http = new Client(['http_errors' => false, 'timeout' => 10, 'connect_timeout' => 2, 'allow_redirects' => false]);
$tokens = json_decode(file_get_contents(__DIR__.'/../../storage/framework/testing/live-tokens.json'), true);
$tls = json_decode(file_get_contents(getenv('PHASE4_TLS_METADATA')), true);
$checks = 0;
function verifyF4(bool $condition, string $message): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException($message);
    } $checks++;
}
function requestF4(string $base, string $method, string $path, int $status, ?array $body = null, ?string $token = null, array $extra = []): array
{
    global $http,$checks;
    if (! preg_match('~^https?://127\.0\.0\.1:[0-9]+$~D', $base)) {
        throw new RuntimeException('Unsafe fixture endpoint.');
    }
    $options = ['headers' => ['Accept' => 'application/json']];
    if ($token) {
        $options['headers']['Authorization'] = 'Bearer '.$token;
    }
    if ($body !== null) {
        $options['json'] = $body;
    }
    foreach ($extra as $key => $value) {
        if ($key === 'headers') {
            $options['headers'] = array_replace($options['headers'], $value);
        } else {
            $options[$key] = $value;
        }
    }
    $response = $http->request($method, $base.$path, $options);
    verifyF4($response->getStatusCode() === $status, "$method $path expected $status received ".$response->getStatusCode());

    return ['body' => json_decode((string) $response->getBody(), true), 'etag' => $response->getHeaderLine('ETag'), 'replay' => $response->getHeaderLine('Idempotency-Replayed')];
}
$remote = getenv('PHASE3_API_REMOTE');
$local = getenv('PHASE3_API_LOCAL');
$props = getenv('PHASE3_PROPERTIES');
$com = getenv('PHASE4_COMMERCIAL');
$com2 = getenv('PHASE4_COMMERCIAL2');
$tlsUrl = getenv('PHASE4_PROPERTIES_TLS');
$jwt = $tokens['remote'];
$read = requestF4($remote, 'GET', '/api/propiedades', 200, null, $jwt)['body'];
$source = $read['data'][0];
$localData = requestF4($local, 'GET', '/api/propiedades', 200, null, $tokens['local'])['body'];
verifyF4($source['id'] !== $localData['data'][0]['id'], 'Fixture IDs must differ between authorities.');
$owner = requestF4($remote, 'GET', '/api/catalogo/opciones-propiedad?kind=propietarios', 200, null, $jwt)['body']['opciones']['data'][0];
verifyF4(! isset($owner['ci']) && ! isset($owner['correo']), 'Selector leaked personal data.');
$cityKey = (string) Str::uuid();
$headers = ['headers' => ['Idempotency-Key' => $cityKey]];
$city = requestF4($remote, 'POST', '/api/ciudades', 201, ['nombre' => 'F4 ciudad', 'departamento' => 'F4'], $jwt, $headers)['body'];
// Reference controllers historically wrap their creation response.
$city = $city['data'] ?? $city;
$cityId = $city['id'];
$again = requestF4($remote, 'POST', '/api/ciudades', 201, ['nombre' => 'F4 ciudad', 'departamento' => 'F4'], $jwt, $headers);
verifyF4($again['replay'] === 'true', 'Remote command was not replayed.');
requestF4($remote, 'POST', '/api/ciudades', 409, ['nombre' => 'Other data', 'departamento' => 'F4'], $jwt, $headers);
$payload = ['propietario_ids' => [$owner['id']], 'sector_urbano_id' => $source['sector_urbano_id'], 'tipo' => 'Lote', 'precio_venta' => '9007199254740993.12', 'moneda' => 'BOB', 'superficie_m2' => '200.00', 'activo' => true, 'ubicacion' => ['referencia' => 'F4 atomic fixture', 'latitud' => -17.3, 'longitud' => -63.2]];
$key = (string) Str::uuid();
$created = requestF4($remote, 'POST', '/api/propiedades', 201, $payload, $jwt, ['headers' => ['Idempotency-Key' => $key]]);
$property = $created['body']['data'];
$id = $property['id'];
verifyF4($property['precio_venta'] === '9007199254740993.12', 'Decimal precision lost in PHP gateway.');
verifyF4(isset($property['ubicacion']['id']), 'Aggregate location missing.');
requestF4($remote, 'GET', '/api/catalogo/operaciones/'.$key, 200, null, $jwt);
$detail = requestF4($remote, 'GET', "/api/propiedades/$id", 200, null, $jwt)['body'];
$payload['direccion'] = 'F4 updated';
requestF4($remote, 'PUT', "/api/propiedades/$id", 200, $payload, $jwt, ['headers' => ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"'.$detail['version'].'"']]);
requestF4($remote, 'PUT', "/api/propiedades/$id", 412, $payload, $jwt, ['headers' => ['If-Match' => '"'.$detail['version'].'"']]);
$public = requestF4($remote, 'GET', "/api/landing/propiedades/$id", 200)['body'];
verifyF4(! isset($public['propietarios']) && $public['precio_venta'] === '9007199254740993.12', 'Public DTO/decimal contract invalid.');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2ioAAAAASUVORK5CYII=');
$photo = requestF4($remote, 'POST', "/api/propiedades/$id/imagenes", 201, null, $jwt, ['headers'=>['Idempotency-Key'=>(string)Str::uuid()],
    'multipart'=>[['name'=>'imagenes[]','contents'=>$png,'filename'=>'fixture.png','headers'=>['Content-Type'=>'image/png']]]])['body']['imagenes'][0];
$nativePhoto = '/api/public/catalogo/imagenes/'.$photo['public_id'].'/'.basename($photo['url']);
$otherRelease = $http->get(getenv('PHASE4_PROPERTIES2').$nativePhoto);
verifyF4($otherRelease->getStatusCode()===200 && hash('sha256',(string)$otherRelease->getBody())===hash('sha256',$png), 'Photo did not survive a different binary release directory.');
$property = requestF4($props, 'GET', "/api/propiedades/$id", 200, null, $jwt)['body'];
$reserve = ['operacion_id' => (string) Str::uuid(), 'command_id' => (string) Str::uuid(), 'expected_version' => $property['version']];
requestF4($tlsUrl, 'POST', "/internal/catalogo/v1/propiedades/$id/reservas", 403, $reserve, $jwt, ['verify' => $tls['server_pem']]);
requestF4($tlsUrl, 'POST', "/internal/catalogo/v1/propiedades/$id/reservas", 401, $reserve, null, ['verify' => $tls['server_pem'], 'cert' => $tls['client_pem'], 'ssl_key' => $tls['client_key']]);
try {
    $wrong = $http->post($tlsUrl."/internal/catalogo/v1/propiedades/$id/reservas", ['headers' => ['Authorization' => 'Bearer '.$jwt], 'json' => $reserve,
        'verify' => $tls['server_pem'], 'cert' => $tls['server_pem'], 'ssl_key' => $tls['server_key']]);
    verifyF4($wrong->getStatusCode() >= 400, 'An unapproved certificate was accepted.');
} catch (GuzzleException $expected) {
    verifyF4(true, 'Unapproved certificate rejected during TLS.');
}
requestF4($props, 'POST', "/internal/catalogo/v1/propiedades/$id/reservas", 403, $reserve, $jwt, ['headers' => ['X-Service' => 'comercial', 'X-Client-Cert' => 'forged']]);
$device = requestF4($remote, 'POST', '/api/login', 200, ['correo' => 'operator@example.test', 'password' => 'Test-only-Password-42!'])['body']['access_token'];
requestF4($remote, 'POST', '/api/logout', 200, null, $device);
requestF4($tlsUrl, 'POST', "/internal/catalogo/v1/propiedades/$id/reservas", 401, $reserve, $device, ['verify' => $tls['server_pem'], 'cert' => $tls['client_pem'], 'ssl_key' => $tls['client_key']]);
$prepared = ['operacion_id' => (string) Str::uuid(), 'command_id' => (string) Str::uuid(), 'propiedad_id' => $id, 'cliente_id' => 1, 'asesor_id' => 1, 'expected_version' => $property['version']];
$second = array_replace($prepared, ['operacion_id' => (string) Str::uuid(), 'command_id' => (string) Str::uuid()]);
$requests = [];
foreach ([[$com, $prepared], [$com2, $second]] as [$url,$body]) {
    $requests[] = $http->postAsync($url.'/api/disponibilidad/operaciones', ['headers' => ['Authorization' => 'Bearer '.$jwt, 'Accept' => 'application/json'], 'json' => $body]);
}
$race = Utils::settle($requests)->wait();
$winners = [];
foreach ($race as $index => $result) {
    verifyF4($result['state'] === 'fulfilled', 'Concurrency transport failed.');
    $status = $result['value']->getStatusCode();
    verifyF4(in_array($status, [200, 409, 412], true), 'Unexpected reservation race status '.$status);
    if ($status === 200) {
        $winners[] = $index;
    }
}
verifyF4(count($winners) === 1, 'Race must have exactly one winner across two service processes.');
$winner = $winners[0];
$base = $winner === 0 ? $com : $com2;
$intent = $winner === 0 ? $prepared : $second;
$held = requestF4($base, 'GET', '/api/disponibilidad/operaciones/'.$intent['operacion_id'], 200, null, $jwt)['body']['reserva'];
verifyF4($held['estado_reserva'] === 'Retenida' && $held['estado_propiedad'] === 'Reservado', 'Own reserved property was incorrectly treated as unavailable.');
verifyF4((string) $held['precio_venta'] !== '0', 'Missing authoritative quote.');
$release = ['command_id' => (string) Str::uuid(), 'reserva_id' => $held['reserva_id'], 'fencing_token' => $held['fencing_token']];
requestF4($base, 'POST', '/api/disponibilidad/operaciones/'.$intent['operacion_id'].'/liberar', 200, $release, $jwt);
requestF4($base, 'POST', '/api/disponibilidad/operaciones/'.$intent['operacion_id'].'/liberar', 200, $release, $jwt);
requestF4($base, 'POST', '/api/disponibilidad/operaciones/'.$intent['operacion_id'].'/confirmar', 404, $release, $jwt);
requestF4($com, 'POST', '/api/ventas', 503, ['solicitud_id' => (string) Str::uuid(), 'cliente_id' => 1, 'asesor_id' => 1, 'propiedad_id' => $id, 'fecha' => '2026-11-04', 'tipo_venta' => 'CONTADO'], $jwt);
$toggle = (string) Str::uuid();
requestF4($remote, 'DELETE', "/api/propiedades/$id", 200, null, $jwt, ['headers' => ['Idempotency-Key' => $toggle]]);
requestF4($remote, 'DELETE', "/api/propiedades/$id", 200, null, $jwt, ['headers' => ['Idempotency-Key' => $toggle]]);
requestF4($remote, 'GET', "/api/landing/propiedades/$id", 404);
// Restore catalogue visibility for browser tests, using a different explicit command.
requestF4($remote, 'DELETE', "/api/propiedades/$id", 200, null, $jwt, ['headers' => ['Idempotency-Key' => (string) Str::uuid()]]);
$sale = json_decode(file_get_contents(__DIR__.'/../../storage/framework/testing/local-sale-fixture.json'), true);
$localSecond = getenv('PHASE4_API_LOCAL2');
$sales = [];
foreach ([$local, $localSecond] as $url) {
    $sales[] = $http->postAsync($url.'/api/ventas', ['headers' => ['Authorization' => 'Bearer '.$tokens['local'], 'Accept' => 'application/json'], 'json' => $sale]);
}
$results = Utils::settle($sales)->wait();
$saleWinners = [];
foreach ($results as $result) {
    verifyF4($result['state'] === 'fulfilled', 'Local sale transport failed.');
    $r = $result['value'];
    verifyF4(in_array($r->getStatusCode(), [201, 400], true), 'Local concurrent sale failed.');
    if ($r->getStatusCode() === 201) {
        $saleWinners[] = json_decode((string) $r->getBody(), true)['data']['id'];
    }
}
verifyF4(count($saleWinners) === 1, 'Local row lock must prevent duplicate sale.');
$cancellations = [];
foreach ([$local, $localSecond] as $url) {
    $cancellations[] = $http->putAsync($url.'/api/ventas/'.$saleWinners[0].'/anular',['headers' => ['Authorization' => 'Bearer '.$tokens['local'], 'Accept' => 'application/json']]);
}
$results = Utils::settle($cancellations)->wait();
$cancelSuccess = 0;
foreach ($results as $result) {
    verifyF4($result['state'] === 'fulfilled','Local cancellation transport failed.');
    $status = $result['value']->getStatusCode();
    verifyF4(in_array($status,[200, 400],true),'Local cancellation race failed.');
    if ($status === 200) {
        $cancelSuccess++;
    }
}
verifyF4($cancelSuccess === 1,'Cancellation must execute once.');
file_put_contents(__DIR__.'/../../storage/framework/testing/integration-f4-result.json',json_encode(['checks' => $checks, 'passed' => true, 'two_property_processes' => true, 'two_commercial_processes' => true, 'actual_mtls' => true, 'finished_at_utc' => gmdate('c')],JSON_PRETTY_PRINT));
echo "F4 live: $checks checks passed.\n";

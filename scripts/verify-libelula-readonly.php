<?php
// No network and no credential loading without --execute. Requires explicit operator authorization.
if (! in_array('--execute', $argv, true)) {
    echo "Acción preparada: POST HTTPS a api.libelula.bo/rest/deuda/consultar_deudas/por_fechas.\n";
    echo "Consulta de un día con la clave ya configurada; no registra deudas ni cobra.\n";
    echo "La ejecución requiere --execute; salida limitada a estado, cantidad y estructura, sin datos personales.\n";
    exit(0);
}
require __DIR__.'/../vendor/autoload.php';
try {
    $date = (new DateTimeImmutable('now', new DateTimeZone('America/La_Paz')))->format('Y-m-d');
    foreach ($argv as $arg) { if (str_starts_with($arg, '--date=')) { $date = substr($arg, 7); } }
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('America/La_Paz'));
    if (! $day || $day->format('Y-m-d') !== $date) { throw new RuntimeException(); }
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $url = rtrim(config('services.libelula.base_url'), '/'); $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'api.libelula.bo'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || (($parts['port'] ?? 443) !== 443) || (($parts['path'] ?? '') !== '')) { throw new RuntimeException(); }
    $key = config('services.libelula.api_key'); if (! is_string($key) || $key === '') { throw new RuntimeException(); }
    $client = new GuzzleHttp\Client(['http_errors' => false, 'allow_redirects' => false, 'verify' => true, 'connect_timeout' => 3, 'timeout' => 10]);
    $response = $client->post($url.'/rest/deuda/consultar_deudas/por_fechas', ['json' => [
        'appkey' => $key, 'fecha_inicial' => $day->format('Y-m-d 00:00:00'), 'fecha_final' => $day->modify('+1 day')->format('Y-m-d 00:00:00'),
    ], 'stream' => true]);
    $stream = $response->getBody(); $bytes = '';
    while (! $stream->eof() && strlen($bytes) <= 1048576) {
        $part = $stream->read(8192); if ($part === '') { break; } $bytes .= $part;
    }
    if ($response->getStatusCode() !== 200 || strlen($bytes) > 1048576) { throw new RuntimeException(); }
    $body = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
    if ((string) ($body['error'] ?? '') !== '0' || ! is_array($body['datos'] ?? null)) { throw new RuntimeException(); }
    $fields = ['identificador', 'pagado', 'valor_total', 'moneda'];
    $recognized = $body['datos'] ? array_reduce($body['datos'], fn ($valid, $row) => $valid && is_array($row) && ! array_diff($fields, array_keys($row)), true) : null;
    echo json_encode(['http' => 200, 'date' => $date, 'records' => count($body['datos']), 'expected_fields_present' => $recognized], JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable) { fwrite(STDERR, "Consulta no validada. No se muestran claves ni datos del proveedor.\n"); exit(1); }

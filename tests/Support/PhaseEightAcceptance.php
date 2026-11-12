<?php

use App\DataMigration\Connections;
use App\DataMigration\Snapshot;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Tests\Support\IsolatedDatabaseGuard;

define('F5_HTTP_HELPERS_ONLY', true);
require __DIR__.'/PhaseFiveIntegrationSmoke.php';

const F8_ACTOR = 'operator-f8@example.test';
const F8_PASSWORD = 'Test-only-Password-42!';
const F8_USD = 'Caja USD ensayo F8';

function rowsF8(PDO $db, string $sql, array $values = []): array
{
    $query = $db->prepare($sql);
    $query->execute($values);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function oneF8(PDO $db, string $sql, array $values = []): array
{
    $rows = rowsF8($db, $sql, $values);
    checkF5(count($rows) === 1, 'La consulta de conciliación debe devolver exactamente una fila.');
    return $rows[0];
}

function mappingF8(PDO $db, string $entity, int $id, bool $reverse = false): int
{
    if ($reverse) {
        $rows = rowsF8($db, "SELECT source_key, locator FROM f7_import_rows WHERE dataset='multilider-local' AND entity=? AND removed=0", [$entity]);
        $row = array_values(array_filter($rows, fn ($row) => (int) array_values(json_decode($row['locator'], true, flags: JSON_THROW_ON_ERROR))[0] === $id));
        checkF5(count($row) === 1, "Falta una equivalencia unívoca para $entity.");
        return (int) $row[0]['source_key'];
    }
    $row = oneF8($db, "SELECT locator FROM f7_import_rows WHERE dataset='multilider-local' AND entity=? AND source_key=? AND removed=0", [$entity, (string) $id]);
    return (int) array_values(json_decode($row['locator'], true, flags: JSON_THROW_ON_ERROR))[0];
}

function clientF8(string $mode): Client
{
    $url = getenv($mode === 'local' ? 'PHASE5_API_LOCAL' : 'PHASE5_API');
    checkF5(preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{3,4}$~D', (string) $url) === 1, 'API aislada requerida.');
    return new Client(['base_uri' => $url, 'http_errors' => false, 'timeout' => 15]);
}

function loginF8(string $mode): array
{
    global $client, $token;
    $client = clientF8($mode);
    $token = null;
    $result = apiF8('POST', 'login', 200, ['correo' => F8_ACTOR, 'password' => F8_PASSWORD]);
    checkF5(isset($result['access_token'], $result['user']['id']), "Identidad de ensayo ausente en $mode.");
    $token = $result['access_token'];
    return $result['user'];
}

function apiF8(string $method, string $route, int $expected, ?array $body = null): array
{
    [$code, $response] = callF5($method, $route, $body);
    checkF5($code === $expected, "$method $route devolvió $code, esperado $expected.");
    return $response ?? [];
}

function pdfF8(string $route): string
{
    global $client, $token;
    $response = $client->get('/api/'.$route, ['headers' => ['Accept' => 'application/pdf', 'Authorization' => 'Bearer '.$token]]);
    $bytes = (string) $response->getBody();
    checkF5($response->getStatusCode() === 200 && str_starts_with($bytes, '%PDF-'),
        "PDF inválido en $route (HTTP {$response->getStatusCode()}).");
    return $bytes;
}

function centsF8(string|int|float $amount): int
{
    return (int) round((float) $amount * 100);
}

function prepareActorF8(PDO $db): int
{
    $hash = password_hash(F8_PASSWORD, PASSWORD_BCRYPT);
    $db->prepare('INSERT INTO users(nombre,correo,password,estado,created_at,updated_at) VALUES(?,?,?,1,NOW(),NOW())')
        ->execute(['Operador F8', F8_ACTOR, $hash]);
    $id = (int) $db->lastInsertId();
    $grant = $db->prepare("INSERT INTO rol_permiso_usuario(user_id,rol_permiso_id,created_at,updated_at)
        SELECT ?,rp.id,NOW(),NOW() FROM rol_permiso rp JOIN roles r ON r.id=rp.rol_id WHERE r.nombre='Administrador' AND r.estado=1");
    $grant->execute([$id]);
    checkF5($grant->rowCount() > 0, 'No se asignaron los permisos de ensayo.');
    return $id;
}

function checkLedgerF8(PDO $db, string $mode, array $payment, int $bank, bool $cancelled = false): array
{
    $remote = $mode === 'remote';
    $rows = $remote
        ? rowsF8($db, 'SELECT Id AS id, Monto AS monto, Moneda AS moneda, Estado AS estado, CuentaBancariaId AS cuenta, UserId AS actor
            FROM Ingresos WHERE PagoId=?', [$payment['id']])
        : rowsF8($db, 'SELECT id,monto,moneda,estado,cuenta_bancaria_id AS cuenta,user_id AS actor FROM ingresos WHERE pago_id=?', [$payment['id']]);
    checkF5(count($rows) === 1, "Se esperaba un solo ingreso en $mode.");
    $income = $rows[0];
    checkF5(centsF8($income['monto']) === $payment['cents'] && $income['moneda'] === ($remote ? 'USD' : '$')
        && $income['estado'] === ($cancelled ? 'ANULADO' : 'CONFIRMADO') && (int) $income['cuenta'] === $bank,
        "El importe, moneda, estado o cuenta del ingreso difiere en $mode.");
    return $income;
}

function accountF8(string $name = F8_USD, string $state = 'Activa'): array
{
    $response = apiF8('POST', 'cuentas-bancarias', 201, ['nombre' => $name, 'tipo' => 'EFECTIVO',
        'estado' => $state, 'moneda' => 'USD', 'saldo_inicial' => 0, 'descripcion' => 'Solo para el ensayo aislado']);
    $account = $response['data'] ?? $response;
    checkF5(isset($account['id']) && centsF8($account['saldo_inicial']) === 0, 'La cuenta de ensayo debe iniciar en cero.');
    return $account;
}

function historicalPaymentsF8(PDO $local, PDO $finance, string $mode): array
{
    $remote = $mode === 'remote';
    $pending = rowsF8($local, "SELECT p.id,p.monto,p.nota_venta_id
        FROM pagos p JOIN notas_ventas v ON v.id=p.nota_venta_id JOIN propiedades x ON x.id=v.propiedad_id
        WHERE x.moneda='USD' AND p.concepto_pago='VENTA_CONTADO' AND p.id_transaccion_libelula IS NULL
        AND p.estado='PENDIENTE_PAGO' ORDER BY p.id");
    checkF5(count($pending) === 2, 'El origen debe conservar los dos cobros USD pendientes.');
    $actor = loginF8($mode);
    $active = apiF8('GET', 'cuentas-bancarias', 200);
    $existing = array_values(array_filter($active, fn ($account) => $account['nombre'] !== F8_USD));
    $account = array_values(array_filter($active, fn ($account) => $account['nombre'] === F8_USD));
    checkF5(count($existing) === 2 && (! $remote || array_column($existing, 'moneda') === ['BOB', 'BOB']),
        'Las dos cuentas de origen deben estar activas y conservar su moneda en Finanzas.');
    checkF5(count($active) === 3 && count($account) === 1
        && (! $remote || $account[0]['moneda'] === 'USD'), 'La API no devuelve la cuenta de ensayo.');
    $bank = (int) $account[0]['id'];
    $methodId = (int) rowsF8($local, "SELECT id FROM metodos_pago WHERE estado='Activo' ORDER BY id")[0]['id'];
    $method = $remote ? mappingF8($finance, 'metodos_pago', $methodId) : $methodId;
    $payments = [];
    foreach ($pending as $row) {
        $source = (int) $row['id'];
        $id = $remote ? mappingF8($finance, 'pagos', $source) : $source;
        $payment = ['source' => $source, 'id' => $id, 'cents' => centsF8($row['monto']), 'method' => $method,
            'sale' => $remote ? (int) oneF8($finance, 'SELECT NotaVentaId FROM CuentasVentas WHERE Id=(SELECT CuentaVentaId FROM Pagos WHERE Id=?)', [$id])['NotaVentaId'] : (int) $row['nota_venta_id'],
            'command_id' => (string) Str::uuid()];
        $body = ['command_id' => $payment['command_id'], 'metodo_pago_id' => $method, 'cuenta_id' => $bank,
            'fecha_pago' => '2026-11-11', 'observaciones' => 'Cobro interno de ensayo F8'];
        $before = apiF8('GET', "pagos/$id", 200);
        checkF5($before['estado'] === 'PENDIENTE_PAGO' && centsF8($before['monto']) === $payment['cents'], 'La obligación histórica cambió.');
        if ($remote && $payments === []) {
            $inactive = accountF8('Caja USD inactiva F8', 'Inactiva');
            foreach ([(int) $existing[0]['id'], (int) $inactive['id']] as $invalidBank) {
                [$status] = callF5('POST', "pagos/$id/procesar", array_replace($body, ['cuenta_id' => $invalidBank, 'command_id' => (string) Str::uuid()]));
                checkF5(in_array($status, [400, 422], true), 'Finanzas aceptó una cuenta inactiva o de otra moneda.');
            }
        }
        $response = apiF8('POST', "pagos/$id/procesar", 200, $body);
        checkF5((int) $response['data']['id'] === $id, 'Se creó un cobro en lugar de confirmar el original.');
        [$replay] = callF5('POST', "pagos/$id/procesar", $body);
        checkF5(in_array($replay, [200, 400, 409], true), 'Resultado inesperado al repetir la confirmación.');
        $after = apiF8('GET', "pagos/$id", 200);
        checkF5($after['estado'] === 'PAGADO' && (int) $after['cuenta_id'] === $bank && centsF8($after['monto']) === $payment['cents'],
            'La consulta posterior no confirmó el importe y la cuenta.');
        $payment['income'] = checkLedgerF8($remote ? $finance : $local, $mode, $payment, $bank);
        checkF5((int) $payment['income']['actor'] === (int) $actor['id'], 'El ingreso se atribuyó a otro actor.');
        $payment['pdf_sha256'] = hash('sha256', pdfF8("pagos/$id/comprobante"));
        $payments[] = $payment;
    }
    $total = array_sum(array_column($payments, 'cents'));
    $date = 'desde=2026-11-11&hasta=2026-11-11';
    $usd = apiF8('GET', "reportes/ventas-cobros?$date&moneda=USD", 200);
    $bob = apiF8('GET', "reportes/ventas-cobros?$date&moneda=BOB", 200);
    checkF5(centsF8($usd['kpis']['total_cobrado']) === $total && centsF8($bob['kpis']['total_cobrado']) === 0,
        'El informe de cobros mezcla monedas o perdió un ingreso.');
    return ['actor' => (int) $actor['id'], 'bank' => $bank, 'payments' => $payments];
}

function localF8(): PDO
{
    if (getenv('DB_DATABASE') === 'multilider_tests_isolated') {
        return Connections::isolatedSource();
    }
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    IsolatedDatabaseGuard::check($app);
    return $app['db']->connection()->getPdo();
}

function manifestF8(string $root, string $label): array
{
    return json_decode(file_get_contents("$root/$label.json"), true, flags: JSON_THROW_ON_ERROR);
}

function reversePaymentF8(PDO $db, string $mode, array $result): void
{
    loginF8($mode);
    [$first, $second] = $result['payments'];
    apiF8('PUT', "pagos/{$first['id']}/cancelar", 200, ['command_id' => uuidF5()]);
    $cancelled = apiF8('GET', "pagos/{$first['id']}", 200);
    checkF5(in_array($cancelled['estado'], ['ANULADO', 'CANCELADO'], true), 'La reversión no conservó el cobro cancelado.');
    checkLedgerF8($db, $mode, $first, $result['bank'], true);
    $pending = $mode === 'remote'
        ? oneF8($db, "SELECT Monto AS monto FROM Pagos WHERE Estado='PENDIENTE_PAGO' AND ConceptoPago='VENTA_CONTADO'
            AND CuentaVentaId=(SELECT CuentaVentaId FROM Pagos WHERE Id=?)", [$first['id']])
        : oneF8($db, "SELECT monto FROM pagos WHERE estado='PENDIENTE_PAGO' AND concepto_pago='VENTA_CONTADO' AND nota_venta_id=?", [$first['sale']]);
    checkF5(centsF8($pending['monto']) === $first['cents'], 'La reversión no restituyó el saldo pendiente.');
    checkLedgerF8($db, $mode, $second, $result['bank']);
}

try {
    $root = Connections::root(dirname(__DIR__, 2));
    checkF5(is_file($root.'/checkpoint-origen-f8.json'), 'El ensayo requiere una copia validada de F7.');
    $stage = $argv[1] ?? '';
    $local = localF8();
    $targets = in_array($stage, ['local', 'local-before', 'local-after', 'account-local', 'reversal-local'], true) ? [] : Connections::targets();
    require __DIR__.'/PhaseEightDelta.php';
    if ($stage === 'prepare') {
        prepareActorF8($local);
        prepareActorF8($targets['seguridad']);
    } elseif (in_array($stage, ['account-remote', 'account-local'], true)) {
        loginF8(substr($stage, 8));
        accountF8();
    } elseif (in_array($stage, ['remote', 'local'], true)) {
        $result = historicalPaymentsF8($local, $targets['finanzas'] ?? $local, $stage);
        Snapshot::writeJson($root."/f8-usd-$stage.json", $result);
    } elseif ($stage === 'local-before') {
        loginF8('local');
        apiF8('GET', 'perfil', 200);
        checkF5(count(apiF8('GET', 'cuentas-bancarias', 200)) === 2, 'El retorno previo a escrituras alteró el catálogo.');
    } elseif ($stage === 'write') {
        Snapshot::writeJson($root.'/f8-cut-keys.json', writeAfterSwitchF8($local, $targets));
    } elseif ($stage === 'capture') {
        Snapshot::writeJson($root.'/f8-delta.json', captureDeltaF8($local, $targets, $root));
    } elseif ($stage === 'remote-after') {
        $restored = captureDeltaF8($local, $targets, $root);
        checkF5($restored === manifestF8($root, 'f8-delta'), 'Las autoridades restauradas difieren del corte.');
        verifyRemoteF8($restored);
    } elseif (in_array($stage, ['reverse', 'reverse-interrupted', 'reverse-conflict', 'reverse-repeat'], true)) {
        $expected = match ($stage) {'reverse' => 'applied', 'reverse-interrupted' => 'interrupted', 'reverse-conflict' => 'conflict', default => 'unchanged'};
        checkF5(reverseDeltaF8($local, $root, $stage) === $expected, 'Resultado de reversión inesperado.');
    } elseif ($stage === 'local-after') {
        verifyLocalF8($local, $root);
    } elseif (in_array($stage, ['reversal-local', 'reversal-remote'], true)) {
        $mode = substr($stage, 9);
        reversePaymentF8($targets['finanzas'] ?? $local, $mode, manifestF8($root, "f8-usd-$mode"));
    } else {
        throw new RuntimeException('Etapa de ensayo desconocida.');
    }
    echo "F8 $stage: $checks comprobaciones aprobadas.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'F8: '.$error->getMessage().PHP_EOL);
    exit(1);
}

<?php

use GuzzleHttp\Handler\CurlMultiHandler;
use Tests\Support\IsolatedDatabaseGuard;

define('F5_ACCEPTANCE_HELPERS_ONLY', true);
require __DIR__.'/PhaseFiveAcceptance.php';

function localStoreF5(): PDO
{
    $database = (string) getenv('DB_DATABASE');
    $port = (int) getenv('MULTILIDER_TEST_PORT');
    if (! IsolatedDatabaseGuard::allowedName($database) || $port < 1024 || $port === 3306 || $port !== (int) getenv('DB_PORT')) {
        throw new RuntimeException('Unsafe local replay database.');
    }

    return new PDO("mysql:host=127.0.0.1;port=$port;dbname=$database", 'multilider_test', getenv('DB_PASSWORD'));
}

try {
    $transport = new CurlMultiHandler(['select_timeout' => 0.1]);
    $client = $remote = endpointF5('PHASE5_API');
    $local = endpointF5('PHASE5_API_LOCAL');
    $token = loginF5();
    $root = realpath(getenv('MULTILIDER_F5_BOUNDARY_DIR'));
    if (! $root || dirname($root) !== realpath(__DIR__.'/../../storage/framework/testing')
        || ! preg_match('/^f5-boundary-[a-f0-9]{32}$/D', basename($root))) {
        throw new RuntimeException('Invalid replay fixture root.');
    }
    $path = $root.DIRECTORY_SEPARATOR.'replay.json';
    $db = localStoreF5();

    if (($argv[1] ?? '') === 'checkpoint') {
        $old = saleF5();
        $oldSale = completedSaleF5($old);
        $cancel = operationF5();
        [$status] = callF5('PUT', "ventas/$oldSale/anular", $cancel);
        checkF5($status === 202 && waitF5($cancel['operacion_id'])['state'] === 'Completada', 'Could not prepare terminal cancellation.');

        $client = $local;
        $token = loginF5();
        $localSale = saleF5();
        [$status, $created] = callF5('POST', 'ventas', $localSale);
        checkF5($status === 201, 'Local checkpoint sale failed.');
        $localId = $created['data']['id'];
        $payment = payF5($localId);
        [$status, $receipt] = callF5('POST', 'pagos', $payment);
        checkF5($status === 201, 'Local checkpoint manual payment failed.');
        $paymentId = $receipt['data']['id'];
        $id = uuidF5();
        $reference = 'TEMP-'.$id;
        // The test order is already reconciled at the checkpoint. The tardy
        // callback must never trigger a provider read or write real debt.
        $insert = $db->prepare("INSERT INTO payment_test_orders
            (id,reference,request_hash,cliente_id,state,amount,currency,selection,created_at,updated_at)
            VALUES (?,?,?,1,'PagadaPrueba',0.20,'BOB','[]',NOW(),NOW())");
        $insert->execute([$id, $reference, str_repeat('a', 64)]);

        $client = $remote;
        $token = loginF5();
        $pending = saleF5();
        $boundary = checkpointF5($pending['operacion_id'], 'ActivarFinanzas', 'before');
        [$status] = callF5('POST', 'ventas', $pending);
        checkF5($status === 202, 'Could not admit pending checkpoint sale.');
        hitF5($boundary);
        [$status, $progress] = callF5('GET', 'operaciones/'.$pending['operacion_id']);
        checkF5($status === 200 && $progress['step'] === 'ActivarFinanzas' && $progress['state'] === 'Pendiente'
            && propertyStateF5($pending['propiedad_id']) === 'Vendido', 'Checkpoint was not at the durable post-confirmation boundary.');
        file_put_contents($path, json_encode(compact('old', 'oldSale', 'cancel', 'pending', 'localId', 'payment', 'paymentId', 'reference'), JSON_THROW_ON_ERROR));
        echo "F5-O06 checkpoint: confirmed property awaiting finance activation plus terminal cancellation and payment receipts.\n";
        exit(0);
    }
    if (($argv[1] ?? '') !== 'verify' || ! preg_match('/^multilider_f5_restore_[a-f0-9]{32}$/D', (string) getenv('DB_DATABASE'))
        || getenv('DB_DATABASE') !== getenv('MULTILIDER_F5_RESTORED_MYSQL')) {
        throw new RuntimeException('Restored replay requires a separate guarded database.');
    }
    $fixture = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    $pending = $fixture['pending'];
    $pendingId = $pending['operacion_id'];
    $deadline = microtime(true) + 75;
    do {
        [$status, $progress] = callF5('GET', 'operaciones/'.$pendingId);
        checkF5($status === 200, 'Restored operation unavailable.');
        if ($progress['state'] === 'Completada') { break; }
        checkF5($progress['state'] === 'Pendiente', 'Restored pending operation reached an unexpected state.');
        usleep(300_000);
    } while (microtime(true) < $deadline);
    checkF5($progress['state'] === 'Completada' && propertyStateF5($pending['propiedad_id']) === 'Vendido', 'Restored worker did not complete the same UUID after lease expiry.');
    [$status, $history] = callF5('GET', 'ventas?tipo_venta=CONTADO&per_page=100');
    checkF5($status === 200 && count(array_filter($history['data'], fn ($row) => $row['propiedad_id'] === $pending['propiedad_id'])) === 1, 'Restored sale was duplicated.');
    [$status, $obligations] = callF5('GET', 'pagos?nota_venta_id='.$progress['venta_id'].'&per_page=100');
    checkF5($status === 200 && count(array_filter($obligations['data'], fn ($row) => $row['nota_venta_id'] === $progress['venta_id']
        && $row['estado'] === 'PENDIENTE_PAGO')) === 1, 'Restored finance activation produced duplicate or missing obligations.');

    $old = $fixture['old'];
    $oldId = $fixture['oldSale'];
    [$status] = callF5('POST', 'ventas', $old);
    checkF5($status === 202 && waitF5($old['operacion_id'])['venta_id'] === $oldId, 'Old sale replay did not return its original resource.');
    [$status, $oldSale] = callF5('GET', "ventas/$oldId");
    checkF5($status === 200 && $oldSale['estado'] === 'Anulada' && propertyStateF5($old['propiedad_id']) === 'Disponible', 'Late sale replay resurrected a closed sale.');
    $new = $old;
    $new['operacion_id'] = uuidF5();
    $new['command_id'] = uuidF5();
    $new['expected_version'] = null;
    $newId = completedSaleF5($new);
    [$status] = callF5('PUT', "ventas/$oldId/anular", $fixture['cancel']);
    checkF5($newId !== $oldId && $status === 202 && waitF5($fixture['cancel']['operacion_id'])['state'] === 'Completada'
        && propertyStateF5($old['propiedad_id']) === 'Vendido', 'Late cancellation replay affected the new generation.');

    $client = $local;
    $token = loginF5();
    [$status, $receipt] = callF5('POST', 'pagos', $fixture['payment']);
    checkF5($status === 201 && $receipt['data']['id'] === $fixture['paymentId'] && count(paymentsF5($fixture['localId'])) === 1, 'Restored local receipt created another payment.');
    $reference = $fixture['reference'];
    $incomeBefore = queryF5($db, 'SELECT COUNT(*) FROM ingresos', []);
    $paymentBefore = queryF5($db, 'SELECT monto FROM pagos WHERE id = ?', [$fixture['paymentId']]);
    $token = null;
    $late = ['identificador' => $reference, 'transaction_id' => 'fixture-late-restore', 'cancel_order' => '1'];
    for ($i = 0; $i < 2; $i++) {
        [$status, $ack] = callF5('POST', 'public/pagos/callback', $late);
        checkF5($status === 200 && $ack['ok'] === true, 'Late fixture callback was not acknowledged.');
    }
    checkF5((int) queryF5($db, 'SELECT COUNT(*) FROM payment_test_inbox WHERE reference = ?', [$reference]) === 1
        && queryF5($db, 'SELECT state FROM payment_test_orders WHERE reference = ?', [$reference]) === 'PagadaPrueba'
        && queryF5($db, 'SELECT COUNT(*) FROM ingresos', []) === $incomeBefore
        && queryF5($db, 'SELECT monto FROM pagos WHERE id = ?', [$fixture['paymentId']]) === $paymentBefore, 'Late duplicate callback changed a terminal receipt or debt.');
    echo "F5-O06: five restored databases restarted; pending UUID recovered, old sale and cancellation replayed, manual receipt and late callback deduplicated.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'F5 restore/replay: '.$error->getMessage().PHP_EOL);
    exit(1);
}

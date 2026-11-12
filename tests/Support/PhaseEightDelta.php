<?php

use Tests\Support\IsolatedDatabaseGuard;

const F8_PROFILE = 'Operador F8 recuperado';
const F8_BRANDING = 'Empresa aislada F8';

function writeAfterSwitchF8(PDO $local, array $targets): array
{
    global $client, $token;
    $actor = loginF8('remote');
    $remoteToken = $token;
    $client = clientF8('local');
    apiF8('GET', 'me', 401);
    $localActor = loginF8('local');
    $localToken = $token;
    $client = clientF8('remote');
    apiF8('GET', 'me', 401);
    $token = $remoteToken;
    apiF8('PUT', 'perfil', 200, ['nombre' => F8_PROFILE, 'correo' => F8_ACTOR]);
    apiF8('POST', 'empresa', 200, ['nombre' => F8_BRANDING]);
    $options = apiF8('GET', 'comercial/opciones', 200);
    $property = array_values(array_filter($options['propiedades'], fn ($row) => $row['moneda'] === 'BOB'))[0];
    $amount = $property['precio_venta'];
    $operation = uuidF5();
    $sale = ['operacion_id' => $operation, 'command_id' => uuidF5(), 'propiedad_id' => $property['id'],
        'cliente_id' => $options['clientes'][0]['id'], 'asesor_id' => $options['asesores'][0]['id'],
        'fecha' => '2026-11-11', 'tipo_venta' => 'CONTADO', 'monto_total' => $amount, 'monto_liquido' => $amount];
    apiF8('POST', 'ventas', 202, $sale);
    // Recover the authoritative receipt from the operation ID even if the admission body is lost.
    $done = waitF5($operation);
    checkF5($done['state'] === 'Completada', 'La venta posterior al cambio no se completó.');
    $saleId = (int) $done['venta_id'];
    $contract = (int) oneF8($targets['comercial'], 'SELECT Id FROM Contratos WHERE NotaVentaId=?', [$saleId])['Id'];
    $pdf = pdfF8("contratos/$contract/generar-pdf");
    $command = uuidF5();
    $response = $client->post("/api/contratos/$contract/gestionar", [
        'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'Idempotency-Key' => $command],
        'multipart' => [
            ['name' => 'archivo', 'contents' => $pdf, 'filename' => 'contrato-f8.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
            ['name' => 'command_id', 'contents' => $command],
            ['name' => 'firmado', 'contents' => '0'],
        ],
    ]);
    checkF5($response->getStatusCode() === 200 && pdfF8("contratos/$contract/descargar") === $pdf, 'No se conservó el nuevo contrato.');
    $client = clientF8('local');
    $token = $localToken;
    apiF8('GET', 'perfil', 200);
    return ['operation' => $operation, 'sale' => $saleId, 'contract' => $contract, 'contract_sha256' => hash('sha256', $pdf),
        'remote_actor' => (int) $actor['id'], 'local_actor' => (int) $localActor['id']];
}

function captureDeltaF8(PDO $local, array $targets, string $root): array
{
    $keys = manifestF8($root, 'f8-cut-keys');
    $usd = manifestF8($root, 'f8-usd-remote');
    ['seguridad' => $identity, 'propiedades' => $catalog, 'comercial' => $sales, 'finanzas' => $finance] = $targets;
    $actor = oneF8($identity, 'SELECT nombre,correo,estado FROM users WHERE id=?', [$keys['remote_actor']]);
    $sale = oneF8($sales, 'SELECT Id,Estado,TipoVenta,Moneda,Fecha,PropiedadId,ClienteId,AsesorId,MontoLiquido,MontoTotal,MontoComision,Descuento FROM Ventas WHERE Id=?', [$keys['sale']]);
    $op = oneF8($sales, 'SELECT Id,Kind,State,VentaId FROM SaleOperation WHERE Id=?', [$keys['operation']]);
    checkF5($op['State'] === 'Completada' && $sale['Estado'] === 'Activa' && $sale['TipoVenta'] === 'CONTADO' && $sale['Moneda'] === 'BOB'
        && (int) $op['VentaId'] === (int) $sale['Id'] && $actor['nombre'] === F8_PROFILE && $actor['correo'] === F8_ACTOR,
        'El corte contiene un estado fuera del alcance del ensayo.');
    $property = oneF8($catalog, 'SELECT id,codigo,estado,moneda FROM propiedades WHERE id=?', [$sale['PropiedadId']]);
    $account = oneF8($finance, 'SELECT Id,NotaVentaId,TipoVenta,Moneda,MontoLiquido,MontoComision,Estado FROM CuentasVentas WHERE NotaVentaId=?', [$sale['Id']]);
    $contract = oneF8($sales, 'SELECT Id,CodigoContrato,FechaEmision,Estado,DocumentoId FROM Contratos WHERE Id=? AND NotaVentaId=?', [$keys['contract'], $sale['Id']]);
    checkF5($property['estado'] === 'Vendido' && $property['moneda'] === $sale['Moneda']
        && $account['Estado'] === 'Activa' && $account['Moneda'] === $sale['Moneda']
        && centsF8($account['MontoLiquido']) === centsF8($sale['MontoLiquido'])
        && centsF8($account['MontoComision']) === centsF8($sale['MontoComision'])
        && $contract['Estado'] === 'Pendiente', 'La venta y su contrato no concilian entre autoridades.');
    $contractPath = documentF8($contract['DocumentoId']);
    checkF5($contractPath !== false && dirname($contractPath) === realpath(getenv('Storage__Path'))
        && hash_file('sha256', $contractPath) === $keys['contract_sha256'], 'El archivo remoto no coincide con el corte.');
    $revision = oneF8($sales, "SELECT Sha256 FROM DocumentRevision WHERE Kind='contrato' AND ResourceId=? AND StorageId=?", [$contract['Id'], $contract['DocumentoId']]);
    checkF5(strtolower($revision['Sha256']) === $keys['contract_sha256'], 'La revisión del contrato no coincide.');
    $newPending = oneF8($finance, 'SELECT Id,ConceptoPago,Monto,Estado,MetodoPagoId,CuentaId,CuotaId FROM Pagos WHERE CuentaVentaId=?', [$account['Id']]);
    checkF5($newPending['Estado'] === 'PENDIENTE_PAGO' && $newPending['ConceptoPago'] === 'VENTA_CONTADO'
        && $newPending['CuotaId'] === null && $newPending['CuentaId'] === null
        && centsF8($newPending['Monto']) === centsF8($sale['MontoLiquido']), 'La nueva venta no conserva su obligación de contado.');
    $commission = rowsF8($finance, 'SELECT Id,Fecha,Concepto,Categoria,Monto,Moneda,Origen,Estado,Beneficiario FROM Egresos WHERE CuentaVentaId=?', [$account['Id']]);
    checkF5(count($commission) === (centsF8($sale['MontoComision']) > 0 ? 1 : 0)
        && ($commission === [] || ($commission[0]['Estado'] === 'PENDIENTE' && $commission[0]['Categoria'] === 'COMISION_ASESOR'
            && $commission[0]['Moneda'] === 'BOB' && centsF8($commission[0]['Monto']) === centsF8($sale['MontoComision']))),
        'La comisión posterior al cambio no concilia.');
    $bank = oneF8($finance, 'SELECT Id,Nombre,Tipo,Moneda,SaldoInicial,Estado FROM CuentasBancarias WHERE Id=?', [$usd['bank']]);
    checkF5($bank['Nombre'] === F8_USD && $bank['Moneda'] === 'USD' && (bool) $bank['Estado'] && centsF8($bank['SaldoInicial']) === 0, 'La cuenta USD del corte cambió.');
    $paid = [];
    foreach ($usd['payments'] as $payment) {
        $p = oneF8($finance, 'SELECT p.Id,p.SolicitudId,p.Monto,p.Estado,p.MetodoPagoId,p.CuentaId,p.FechaPago,p.Observaciones,c.Moneda,c.NotaVentaId
            FROM Pagos p JOIN CuentasVentas c ON c.Id=p.CuentaVentaId WHERE p.Id=?', [$payment['id']]);
        $income = checkLedgerF8($finance, 'remote', $payment, $usd['bank']);
        checkF5($p['Estado'] === 'PAGADO' && $p['Moneda'] === 'USD' && strtolower($p['SolicitudId']) === $payment['command_id']
            && (int) $p['CuentaId'] === $usd['bank'] && (int) $p['NotaVentaId'] === $payment['sale']
            && centsF8($p['Monto']) === $payment['cents'] && (int) $income['actor'] === $keys['remote_actor'],
            'El cobro USD confirmado y su comando no concilian.');
        $paid[] = $p + ['source' => $payment['source'], 'local_method' => mappingF8($finance, 'metodos_pago', (int) $p['MetodoPagoId'], true), 'income' => $income];
    }
    $company = oneF8($local, 'SELECT id,nombre FROM mi_empresa');
    checkF5($company['nombre'] === F8_BRANDING, 'No se conservó el cambio de empresa en Laravel.');
    $links = ['propiedad_id' => mappingF8($catalog, 'propiedades', (int) $sale['PropiedadId'], true),
        'cliente_id' => mappingF8($sales, 'clientes', (int) $sale['ClienteId'], true),
        'asesor_id' => mappingF8($sales, 'asesores', (int) $sale['AsesorId'], true)];
    return compact('keys', 'actor', 'op', 'sale', 'property', 'account', 'contract', 'newPending', 'commission', 'bank', 'paid', 'company', 'links');
}

function documentF8(string $id): string|false
{
    $id = str_replace('-', '', strtolower($id));
    checkF5(preg_match('/^[a-f0-9]{32}$/D', $id) === 1, 'Identificador de documento inválido.');
    return realpath(getenv('Storage__Path').DIRECTORY_SEPARATOR.$id.'.pdf');
}

function verifyRemoteF8(array $delta): void
{
    loginF8('remote');
    $done = waitF5($delta['keys']['operation']);
    checkF5($done['state'] === 'Completada' && (int) $done['venta_id'] === $delta['keys']['sale'], 'Se perdió el recibo de coordinación.');
    checkF5(hash('sha256', pdfF8("contratos/{$delta['keys']['contract']}/descargar")) === $delta['keys']['contract_sha256'], 'El contrato restaurado difiere.');
    foreach ($delta['paid'] as $payment) {
        checkF5(apiF8('GET', "pagos/{$payment['Id']}", 200)['estado'] === 'PAGADO', 'Se perdió un cobro confirmado.');
        pdfF8("pagos/{$payment['Id']}/comprobante");
    }
}

function insertF8(PDO $db, string $table, array $row): int
{
    $columns = implode(',', array_keys($row));
    $placeholders = implode(',', array_fill(0, count($row), '?'));
    $db->prepare("INSERT INTO $table ($columns,created_at,updated_at) VALUES ($placeholders,NOW(),NOW())")->execute(array_values($row));
    return (int) $db->lastInsertId();
}

function reverseDeltaF8(PDO $db, string $root, string $stage): string
{
    checkF5(getenv('DB_DATABASE') === getenv('MULTILIDER_F5_RESTORED_MYSQL') && getenv('APP_ARCHITECTURE') === 'local',
        'El delta solo se puede aplicar a la copia Laravel restaurada y detenida.');
    $delta = manifestF8($root, 'f8-delta');
    ['keys' => $keys, 'sale' => $sale, 'links' => $links, 'contract' => $contract] = $delta;
    $operation = $keys['operation'];
    $hash = hash_file('sha256', "$root/f8-delta.json");
    checkF5(hash_equals((string) getenv('MULTILIDER_F8_DELTA_SHA256'), $hash), 'La huella del delta no coincide con el corte.');
    // This ledger is private to the restored test DB. Its transaction covers all relational writes.
    $db->exec('CREATE TABLE IF NOT EXISTS f8_reverse_receipts (operation_id CHAR(36) PRIMARY KEY, manifest_hash CHAR(64) NOT NULL, result JSON NOT NULL)');
    $db->beginTransaction();
    try {
        $receipt = rowsF8($db, 'SELECT manifest_hash,result FROM f8_reverse_receipts WHERE operation_id=? FOR UPDATE', [$operation]);
        if ($receipt !== []) {
            checkF5(hash_equals($receipt[0]['manifest_hash'], $hash), 'La misma operación tiene un delta diferente.');
            $db->rollBack();
            return 'unchanged';
        }
        $actor = oneF8($db, 'SELECT id,nombre FROM users WHERE id=? AND correo=?', [$keys['local_actor'], F8_ACTOR]);
        $property = oneF8($db, 'SELECT codigo,estado,moneda FROM propiedades WHERE id=? FOR UPDATE', [$links['propiedad_id']]);
        $conflict = $property !== ['codigo' => $delta['property']['codigo'], 'estado' => 'Disponible', 'moneda' => 'BOB']
            || rowsF8($db, 'SELECT id FROM contratos WHERE codigo_contrato=?', [$contract['CodigoContrato']]) !== []
            || rowsF8($db, 'SELECT id FROM cuentas_bancarias WHERE nombre=?', [F8_USD]) !== []
            || $actor['nombre'] !== 'Operador F8';
        if ($conflict) {
            $db->rollBack();
            return 'conflict';
        }
        $bank = insertF8($db, 'cuentas_bancarias', ['mi_empresa_id' => $delta['company']['id'], 'nombre' => F8_USD,
            'tipo' => $delta['bank']['Tipo'], 'estado' => 'Activa', 'saldo_inicial' => $delta['bank']['SaldoInicial'],
            'descripcion' => 'USD, cuenta aislada de ensayo F8']);
        if ($stage === 'reverse-interrupted') {
            $db->rollBack();
            checkF5(rowsF8($db, 'SELECT id FROM cuentas_bancarias WHERE nombre=?', [F8_USD]) === [], 'La interrupción dejó datos parciales.');
            return 'interrupted';
        }
        $db->prepare('UPDATE users SET nombre=?,updated_at=NOW() WHERE id=?')->execute([$delta['actor']['nombre'], $actor['id']]);
        foreach ($delta['paid'] as $payment) {
            $prior = oneF8($db, 'SELECT nota_venta_id,estado,monto FROM pagos WHERE id=? AND id_transaccion_libelula IS NULL FOR UPDATE', [$payment['source']]);
            checkF5($prior['estado'] === 'PENDIENTE_PAGO' && centsF8($prior['monto']) === centsF8($payment['Monto'])
                && rowsF8($db, 'SELECT id FROM ingresos WHERE pago_id=?', [$payment['source']]) === [], 'El pago local ya cambió.');
            $date = substr($payment['FechaPago'], 0, 10);
            $db->prepare("UPDATE pagos SET estado='PAGADO',cuenta_id=?,metodo_pago_id=?,fecha_pago=?,observaciones=?,updated_at=NOW() WHERE id=?")
                ->execute([$bank, $payment['local_method'], $date, $payment['Observaciones'], $payment['source']]);
            insertF8($db, 'ingresos', ['fecha' => $date, 'concepto' => 'VENTA_CONTADO - Venta #'.$prior['nota_venta_id'],
                'categoria' => 'VENTA_CONTADO', 'monto' => $payment['Monto'], 'moneda' => '$', 'origen' => 'AUTOMATICO',
                'pago_id' => $payment['source'], 'nota_venta_id' => $prior['nota_venta_id'], 'cuenta_bancaria_id' => $bank,
                'user_id' => $actor['id'], 'observaciones' => $payment['Observaciones'], 'estado' => 'CONFIRMADO']);
        }
        $db->prepare("UPDATE propiedades SET estado='Vendido',updated_at=NOW() WHERE id=?")->execute([$links['propiedad_id']]);
        $localSale = insertF8($db, 'notas_ventas', $links + ['fecha' => substr($sale['Fecha'], 0, 10),
            'monto_total' => $sale['MontoTotal'], 'monto_liquido' => $sale['MontoLiquido'], 'monto_comision' => $sale['MontoComision'],
            'descuento' => $sale['Descuento'], 'tipo_venta' => 'CONTADO', 'estado' => 'Completada']);
        $pending = insertF8($db, 'pagos', ['nota_venta_id' => $localSale, 'concepto_pago' => 'VENTA_CONTADO',
            'monto' => $delta['newPending']['Monto'], 'estado' => 'PENDIENTE_PAGO']);
        $localContract = insertF8($db, 'contratos', ['nota_venta_id' => $localSale, 'codigo_contrato' => $contract['CodigoContrato'],
            'fecha_emision' => substr($contract['FechaEmision'], 0, 10), 'tipo_venta' => 'Contado', 'estado' => 'Pendiente']);
        $file = "contratos/$localContract/".$keys['contract_sha256'].'.pdf';
        foreach ($delta['commission'] as $commission) {
            insertF8($db, 'egresos', ['fecha' => substr($commission['Fecha'], 0, 10), 'concepto' => $commission['Concepto'],
                'categoria' => $commission['Categoria'], 'monto' => $commission['Monto'], 'moneda' => 'Bs',
                'origen' => $commission['Origen'], 'estado' => $commission['Estado'], 'beneficiario' => $commission['Beneficiario'],
                'nota_venta_id' => $localSale, 'asesor_id' => $links['asesor_id'], 'user_id' => $actor['id']]);
        }
        $source = documentF8($contract['DocumentoId']);
        $destination = IsolatedDatabaseGuard::phaseSevenStorage().'/app/public/'.$file;
        checkF5($source !== false && dirname($source) === realpath(getenv('Storage__Path'))
            && hash_file('sha256', $source) === $keys['contract_sha256'], 'No se puede trasladar el archivo del corte.');
        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0700, true);
        }
        if (! is_file($destination)) {
            $staged = $destination.'.tmp';
            checkF5(copy($source, $staged) && hash_file('sha256', $staged) === $keys['contract_sha256']
                && rename($staged, $destination), 'No se pudo preparar el PDF del retorno.');
        }
        checkF5(hash_file('sha256', $destination) === $keys['contract_sha256'], 'El PDF local no coincide con el remoto.');
        $db->prepare('UPDATE contratos SET url_doc=? WHERE id=?')->execute(['/storage/'.$file, $localContract]);
        $db->prepare('INSERT INTO f8_reverse_receipts VALUES(?,?,?)')
            ->execute([$operation, $hash, \App\DataMigration\Snapshot::json(compact('localSale', 'localContract', 'bank', 'pending'))]);
        $db->commit();
        return 'applied';
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function verifyLocalF8(PDO $db, string $root): void
{
    $delta = manifestF8($root, 'f8-delta');
    $receipt = oneF8($db, 'SELECT result FROM f8_reverse_receipts WHERE operation_id=?', [$delta['keys']['operation']]);
    $result = json_decode($receipt['result'], true, flags: JSON_THROW_ON_ERROR);
    $actor = loginF8('local');
    checkF5($actor['nombre'] === F8_PROFILE && apiF8('GET', 'empresa/datos', 200)['empresa']['nombre'] === F8_BRANDING, 'El retorno perdió perfil o empresa.');
    $sale = oneF8($db, 'SELECT estado,monto_liquido,propiedad_id FROM notas_ventas WHERE id=?', [$result['localSale']]);
    $pending = apiF8('GET', "pagos/{$result['pending']}", 200);
    checkF5($sale['estado'] === 'Completada' && centsF8($sale['monto_liquido']) === centsF8($delta['sale']['MontoLiquido'])
        && (int) $sale['propiedad_id'] === $delta['links']['propiedad_id']
        && apiF8('GET', 'propiedades/'.$sale['propiedad_id'], 200)['estado'] === 'Vendido'
        && $pending['estado'] === 'PENDIENTE_PAGO' && centsF8($pending['monto']) === centsF8($delta['newPending']['Monto']),
        'La venta devuelta a Laravel no concilia.');
    checkF5(hash('sha256', pdfF8("contratos/{$result['localContract']}/descargar")) === $delta['keys']['contract_sha256'], 'El PDF cambió durante el retorno.');
    foreach ($delta['paid'] as $payment) {
        $id = $payment['source'];
        checkF5(apiF8('GET', "pagos/$id", 200)['estado'] === 'PAGADO', 'El retorno perdió un pago.');
        checkLedgerF8($db, 'local', ['id' => $id, 'cents' => centsF8($payment['Monto'])], $result['bank']);
        pdfF8("pagos/$id/comprobante");
    }
}

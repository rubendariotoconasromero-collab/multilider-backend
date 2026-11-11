<?php

namespace App\DataMigration;

use PDOException;
use RuntimeException;

final class Importer
{
    private array $targets;
    private array $rows = [];
    private array $old = [];
    private array $mapped = [];
    private array $issues = [];
    private array $sales = [];
    private array $documents = [];
    private array $currencies = [];
    private array $accountCurrencies = [];
    private array $activeProperties = [];
    private array $financialTotals = [];
    private array $verifiedFiles = [];
    private array $decisionHashes = [];
    private string $dataset;
    private string $issuer;

    public function __construct(private Snapshot $source, array $connections, private string $root,
        private string $mode, private ?Snapshot $previous = null)
    {
        $this->dataset = $source->manifest['dataset'];
        $this->issuer = getenv('IDENTITY_ISSUER') ?: 'phase3-isolated';
        if ($previous !== null && ($previous->manifest['dataset'] !== $this->dataset
            || $previous->manifest['source'] !== $source->manifest['source']
            || $previous->manifest['cutoff_utc'] > $source->manifest['cutoff_utc'])) {
            throw new RuntimeException('El delta no pertenece al mismo origen, dataset y orden de corte.');
        }
        $this->targets = array_map(fn ($db) => new Target($db, $this->dataset, $mode), $connections);
    }

    public function run(): array
    {
        foreach (Snapshot::TABLES as $table) {
            $this->rows[$table] = iterator_to_array($this->source->rows($table));
            if ($this->previous !== null) {
                $this->old[$table] = iterator_to_array($this->previous->rows($table));
            }
        }
        $this->preflight();
        if ($this->mode !== 'apply' || $this->issues === []) {
            foreach ($this->targets as $target) {
                $target->initialize();
            }
            $this->removeRetired();
            $this->identity();
            $this->catalog();
            $this->commercial();
            $this->finance();
        }

        $accountCurrencies = [];
        foreach ($this->accountCurrencies as $id => $currency) {
            if ($this->enabled($this->rows['cuentas_bancarias'][$id]['estado'])) {
                $accountCurrencies[$currency] = true;
            }
        }
        $pending = array_keys(array_filter($this->financialTotals['pagos'] ?? [], fn ($states) => ($states['PENDIENTE_PAGO'] ?? 0) > 0));

        return [
            'dataset' => $this->dataset, 'cutoff_utc' => $this->source->manifest['cutoff_utc'],
            'summary' => ['blocked' => count($this->issues), 'files' => count($this->verifiedFiles),
                'services' => array_map(fn ($target) => $target->counts, $this->targets)],
            'source_counts' => array_map('count', $this->rows), 'financial_cents' => $this->financialTotals,
            'decision_hashes' => $this->decisionHashes, 'pending_currencies_without_account' => array_values(array_diff($pending, array_keys($accountCurrencies))),
            'laravel' => ['empresa' => count($this->rows['mi_empresa']), 'campanas' => count($this->rows['campanas_correo']),
                'destinatarios' => count($this->rows['campana_destinatarios'])],
            'issues' => $this->issues,
        ];
    }

    private function preflight(): void
    {
        foreach ($this->source->manifest['assets'] as $key => $entry) {
            if (isset($entry['error'])) {
                $this->issues[] = ['entity' => $key, 'reason' => $entry['error']];
            }
        }
        $evidence = $this->decisions('sale-currencies.json');
        foreach (['ingresos', 'egresos'] as $table) {
            foreach ($this->rows[$table] as $id => $row) {
                $this->inspect($table, $id, function () use (&$evidence, $row, $table) {
                    $currency = $this->currency($row['moneda']);
                    $this->addTotal($table, $currency, $row['estado'], $row['monto']);
                    if ($row['nota_venta_id'] !== null) {
                        $evidence[$row['nota_venta_id']]['currencies'][$currency] = true;
                    }
                });
            }
        }
        $contracts = array_count_values(array_column($this->rows['contratos'], 'nota_venta_id'));
        foreach ($this->rows['notas_ventas'] as $id => $row) {
            $this->inspect('notas_ventas', $id, function () use ($evidence, $row, $id, $contracts) {
                $proof = $evidence[$id] ?? [];
                $choices = array_keys($proof['currencies'] ?? []);
                if (isset($proof['moneda']) && is_string($proof['evidencia'] ?? null) && trim($proof['evidencia']) !== '') {
                    $choices[] = $this->currency($proof['moneda']);
                }
                if (isset($row['moneda'])) {
                    $choices[] = $this->currency($row['moneda']);
                }
                if (count(array_unique($choices)) !== 1) {
                    throw new RuntimeException('Falta evidencia inequívoca de la moneda histórica.');
                }
                $this->currencies[$id] = $choices[0];
                $this->addTotal('ventas', $choices[0], $row['estado'], $row['monto_total']);
                if (in_array($row['estado'], ['Activa', 'Completada'], true)) {
                    $property = $row['propiedad_id'];
                    if (isset($this->activeProperties[$property]) || ($this->rows['propiedades'][$property]['estado'] ?? null) !== 'Vendido') {
                        throw new RuntimeException('La propiedad no tiene una única venta activa en estado vendido.');
                    }
                    $this->activeProperties[$property] = $id;
                    if (($contracts[$id] ?? 0) !== 1) {
                        throw new RuntimeException('Una venta vigente requiere un contrato único para su continuidad.');
                    }
                } elseif ($row['estado'] !== 'Anulada') {
                    throw new RuntimeException('Estado de venta requiere conciliación.');
                }
            });
        }
        foreach ($this->rows['propiedades'] as $id => $row) {
            if ($row['estado'] === 'Reservado' || ($row['estado'] === 'Vendido' && ! isset($this->activeProperties[$id]))) {
                $this->issue('propiedades', $id, 'La disponibilidad no tiene un vínculo histórico comprobado.');
            }
        }
        foreach ($this->rows['pagos'] as $id => $row) {
            $this->inspect('pagos', $id, function () use ($row) {
                $currency = $this->currencies[$row['nota_venta_id']] ?? throw new RuntimeException('Falta la moneda comprobada de la venta.');
                if ($row['estado'] === 'PENDIENTE_PAGO' && (! empty($row['id_transaccion_libelula']) || ! empty($row['libelula_uuid']))) {
                    throw new RuntimeException('Hay una referencia externa pendiente de revisión.');
                }
                if (($row['estado'] === 'PAGADO' && empty($row['fecha_pago']))
                    || str_starts_with((string) ($row['codigo_recaudacion_libelula'] ?? ''), 'TEMP-')) {
                    throw new RuntimeException('El pago requiere revisión de fecha o clasificación de prueba.');
                }
                $this->addTotal('pagos', $currency, $row['estado'], $row['monto']);
            });
        }
        $accountEvidence = $this->decisions('account-currencies.json');
        foreach ($this->rows['cuentas_bancarias'] as $id => $row) {
            $this->inspect('cuentas_bancarias', $id, function () use ($accountEvidence, $row, $id) {
                $choices = [];
                foreach (['ingresos', 'egresos'] as $table) {
                    foreach ($this->rows[$table] as $movement) {
                        if ((int) $movement['cuenta_bancaria_id'] === $id) {
                            $choices[] = $this->currency($movement['moneda']);
                        }
                    }
                }
                foreach ($this->rows['pagos'] as $payment) {
                    if ((int) $payment['cuenta_id'] === $id && isset($this->currencies[$payment['nota_venta_id']])) {
                        $choices[] = $this->currencies[$payment['nota_venta_id']];
                    }
                }
                if (isset($row['moneda'])) {
                    $choices[] = $this->currency($row['moneda']);
                }
                $proof = $accountEvidence[$id] ?? [];
                if (isset($proof['moneda']) && is_string($proof['evidencia'] ?? null) && trim($proof['evidencia']) !== '') {
                    $choices[] = $this->currency($proof['moneda']);
                }
                if (count(array_unique($choices)) !== 1) {
                    throw new RuntimeException('La moneda de la cuenta requiere evidencia.');
                }
                $this->accountCurrencies[$id] = $choices[0];
            });
        }
        $incomeByPayment = [];
        foreach ($this->rows['ingresos'] as $id => $row) {
            if ($row['pago_id'] === null) {
                continue;
            }
            $this->inspect('ingresos', $id, function () use (&$incomeByPayment, $row) {
                $payment = $this->rows['pagos'][$row['pago_id']] ?? throw new RuntimeException('El ingreso no tiene pago de origen.');
                if (isset($incomeByPayment[$row['pago_id']]) || (int) $payment['nota_venta_id'] !== (int) $row['nota_venta_id']
                    || $this->cents($payment['monto']) !== $this->cents($row['monto'])
                    || ($this->currencies[$payment['nota_venta_id']] ?? null) !== $this->currency($row['moneda'])
                    || ($row['estado'] === 'CONFIRMADO' && $payment['estado'] !== 'PAGADO')) {
                    throw new RuntimeException('Ingreso duplicado o incompatible con el pago.');
                }
                $incomeByPayment[$row['pago_id']] = $row;
            });
        }
        foreach ($this->rows['pagos'] as $id => $row) {
            if ($row['estado'] === 'PAGADO' && ! isset($incomeByPayment[$id])) {
                $this->issue('pagos', $id, 'Pago confirmado sin ingreso conciliado.');
            }
        }
        $this->balances();
    }

    private function identity(): void
    {
        foreach (['users' => 'nombre correo password estado', 'roles' => 'nombre descripcion estado', 'permisos' => 'nombre descripcion estado'] as $table => $fields) {
            $this->batch('seguridad', $table, $table, function ($r) use ($fields, $table) {
                if ($table === 'users' && ! preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/D', $r['password'])) {
                    throw new RuntimeException('Hash incompatible. Requiere un flujo de restablecimiento.');
                }
                $data = $this->fields($r, $fields.' created_at updated_at');

                return [$data, [], [$table === 'users' ? ['correo' => trim($r['correo'])] : ['nombre' => $r['nombre']]]];
            });
        }
        $this->batch('seguridad', 'rol_permiso', 'rol_permiso', fn ($r) => [
            ['rol_id' => $this->id('roles', $r['rol_id']), 'permiso_id' => $this->id('permisos', $r['permiso_id'])]
            + $this->fields($r, 'created_at updated_at'),
        ]);
        $this->batch('seguridad', 'rol_permiso_usuario', 'rol_permiso_usuario', fn ($r) => [
            ['user_id' => $this->id('users', $r['user_id']), 'rol_permiso_id' => $this->id('rol_permiso', $r['rol_permiso_id'])]
            + $this->fields($r, 'created_at updated_at'),
        ]);
    }

    private function catalog(): void
    {
        $this->batch('propiedades', 'ciudades', 'ciudades', fn ($r) => [
            $this->fields($r, 'nombre departamento estado', 'pascal') + $this->fields($r, 'created_at updated_at'),
        ]);
        $this->batch('propiedades', 'distritos', 'distritos', fn ($r) => [
            ['ciudad_id' => $this->id('ciudades', $r['ciudad_id'])] + $this->fields($r, 'nombre estado', 'pascal') + $this->fields($r, 'created_at updated_at'),
        ]);
        $this->batch('propiedades', 'sectores_urbanos', 'sectores_urbanos', fn ($r) => [
            ['distrito_id' => $this->id('distritos', $r['distrito_id'])] + $this->fields($r, 'nombre tipo uv manzano estado', 'pascal')
            + $this->fields($r, 'created_at updated_at'),
        ]);
        $this->batch('propiedades', 'propietarios', 'propietarios', fn ($r) => [
            $this->fields($r, 'lugar_expedicion nombre_completo nombre_empresa created_at updated_at')
            + $this->fields($r, 'tipo ci nit telefono correo direccion estado', 'pascal'), [], [['Ci' => $r['ci']]],
        ]);
        $this->batch('propiedades', 'ubicaciones', 'ubicaciones', fn ($r) => [
            $this->fields($r, 'url_maps created_at updated_at') + $this->fields($r, 'referencia longitud latitud', 'pascal'),
        ]);
        $this->batch('propiedades', 'caracteristicas', 'caracteristicas', fn ($r) => [
            $this->fields($r, 'nombre tipo estado', 'pascal') + $this->fields($r, 'created_at updated_at'), [], [['Nombre' => $r['nombre']]],
        ]);
        $this->batch('propiedades', 'propiedades', 'propiedades', fn ($r) => [
            ['sector_urbano_id' => $this->id('sectores_urbanos', $r['sector_urbano_id']), 'ubicacion_id' => $this->id('ubicaciones', $r['ubicacion_id']),
                'UltimoFence' => isset($this->activeProperties[$r['id']]) ? 1 : 0]
            + $this->fields($r, 'codigo tipo moneda habitaciones banos direccion estado activo', 'pascal')
            + $this->fields($r, 'precio_venta superficie_m2 superficie_construida_m2 frente_mts fondo_mts es_esquina nro_lote colinda_norte colinda_sur colinda_este colinda_oeste es_destacado created_at updated_at'),
            [], [['Codigo' => $r['codigo']]],
        ]);
        foreach (['propiedad_propietario' => 'propietario', 'caracteristica_propiedad' => 'caracteristica'] as $pivot => $other) {
            $table = $other === 'propietario' ? 'propietarios' : 'caracteristicas';
            $this->batch('propiedades', $pivot, $pivot, fn ($r) => [
                ['propiedades_id' => $this->id('propiedades', $r['propiedad_id']), $table.'_id' => $this->id($table, $r[$other.'_id'])],
                ['propiedades_id' => $this->id('propiedades', $r['propiedad_id']), $table.'_id' => $this->id($table, $r[$other.'_id'])],
            ]);
        }
        $this->batch('propiedades', 'imagenes_propiedades', 'imagenes_propiedades', function ($r) {
            $key = "imagenes_propiedades/{$r['id']}/url";
            $asset = $this->source->asset($key) ?? throw new RuntimeException('La imagen carece de archivo.');
            $publicId = $this->uuid($key);
            $ext = $this->extension($asset, false);
            $file = 'objects/'.str_replace('-', '', $this->uuid($key.'/'.$asset['sha256'])).'.'.$ext;
            $this->copyAsset('catalog', $file, $asset);

            return [[
                'propiedad_id' => $this->id('propiedades', $r['propiedad_id']), 'PublicId' => $publicId,
                'StorageKey' => $file, 'ContentHash' => strtoupper($asset['sha256']), 'ContentType' => $asset['type'], 'Size' => $asset['bytes'],
                'Publicada' => 1, 'Url' => "/api/public/catalogo/imagenes/$publicId/".strtoupper($asset['sha256']),
            ] + $this->fields($r, 'es_principal created_at updated_at'), [], [['PublicId' => $publicId]]];
        });
    }

    private function commercial(): void
    {
        foreach (['clientes' => 'ci lugar_expedicion', 'asesores' => 'porcentaje_comision'] as $table => $extra) {
            $this->batch('comercial', $table, ucfirst($table), function ($r) use ($table, $extra) {
                $user = $this->id('users', $r['user_id']);
                $data = ['UserId' => $user, 'IdentityIssuer' => $user === null ? null : $this->issuer,
                    'IdentitySubject' => $user === null ? null : (string) $user]
                    + $this->fields($r, "nombre_completo telefono correo direccion estado $extra", 'pascal')
                    + $this->timestamps($r, "$table/{$r['id']}");
                if ($table === 'asesores' && ! empty($r['foto'])) {
                    $asset = $this->source->asset("asesores/{$r['id']}/foto");
                    $this->extension($asset, false);
                    $this->copyAsset('photos', $asset['sha256'], $asset);
                    $data += ['PhotoId' => $asset['sha256'], 'PhotoMime' => $asset['type']];
                }

                return [$data, [], $table === 'clientes' ? [['Ci' => $r['ci']]] : []];
            });
        }
        $this->batch('comercial', 'notas_ventas', 'Ventas', function ($r) {
            $id = $r['id'];
            $property = $this->rows['propiedades'][$r['propiedad_id']];
            $plan = collect($this->rows['planes_pagos'])->firstWhere('nota_venta_id', $id);
            $state = $r['estado'] === 'Anulada' ? 'Anulada' : 'Activa';
            $currency = $this->currencies[$id] ?? throw new RuntimeException('Falta moneda histórica.');
            $stored = $this->targets['comercial']->mappedValues('notas_ventas', (string) $id, ['DocumentSnapshot']);
            $doc = $stored === null ? $this->saleDocument($r, $currency)
                : json_decode($stored['DocumentSnapshot'], true, flags: JSON_THROW_ON_ERROR);
            $this->sales[$id] = [
                'operation' => $this->uuid("venta/$id"), 'state' => $state, 'currency' => $currency, 'document' => $doc,
                'property' => $this->id('propiedades', $r['propiedad_id']), 'client' => $this->id('clientes', $r['cliente_id']),
                'adviser' => $this->id('asesores', $r['asesor_id']),
            ];
            $data = [
                'SolicitudId' => $this->sales[$id]['operation'], 'SolicitudHash' => hash('sha256', $this->dataset.'/venta/'.$id),
                'ClienteId' => $this->sales[$id]['client'], 'AsesorId' => $this->sales[$id]['adviser'], 'PropiedadId' => $this->sales[$id]['property'],
                'PropiedadActivaId' => $state === 'Activa' ? $this->sales[$id]['property'] : null, 'CodigoPropiedad' => $property['codigo'] ?? '',
                'Moneda' => $currency, 'Estado' => $state, 'DocumentSnapshot' => Snapshot::json($doc),
                'NumeroCuotas' => $plan['numero_cuotas'] ?? 0, 'TasaInteres' => $plan['tasa_interes'] ?? 0,
                'TasaMora' => $plan['tasa_mora'] ?? 0, 'FechaInicioPago' => $plan['fecha_inicio'] ?? null,
                'Descuento' => $r['descuento'] ?? 0, 'MontoComision' => $r['monto_comision'] ?? 0,
                'MontoLiquido' => $r['monto_liquido'] ?? $r['monto_total'], 'CuotaInicial' => $r['cuota_inicial'] ?? 0,
                'SaldoCredito' => $r['saldo_credito'] ?? 0,
            ] + $this->fields($r, 'fecha tipo_venta monto_total', 'pascal') + $this->timestamps($r, "venta/$id");

            return [$data, [], [['SolicitudId' => $data['SolicitudId']], ['PropiedadActivaId' => $data['PropiedadActivaId']]]];
        });
        $this->batch('comercial', 'contratos', 'Contratos', function ($r) {
            $file = $this->document('contratos', $r, 'url_doc', 'contrato');

            return [[
                'NotaVentaId' => $this->id('notas_ventas', $r['nota_venta_id']), 'DocumentoId' => $file,
            ] + $this->fields($r, 'codigo_contrato fecha_emision fecha_firma estado', 'pascal')
            + $this->timestamps($r, "contrato/{$r['id']}"), [], [['CodigoContrato' => $r['codigo_contrato']]]];
        });
        $this->batch('comercial', 'entregas', 'Entregas', function ($r) {
            $file = $this->document('entregas', $r, 'url_acta', 'acta');

            return [[
                'ContratoId' => $this->id('contratos', $r['contrato_id']), 'ActaId' => $file,
            ] + $this->fields($r, 'fecha_programada fecha_entrega estado condicion_inmueble items_entregados observaciones entregado_por recibido_por', 'pascal')
            + $this->timestamps($r, "entrega/{$r['id']}")];
        });
        foreach ($this->documents as $key => [$table, $id, $kind, $file, $asset, $date]) {
            $this->inspect('revisiones_documento', $id, function () use ($key, $table, $id, $kind, $file, $asset, $date) {
                $this->targets['comercial']->row('revisiones_documento', hash('sha256', $key.'/'.$asset['sha256']), 'DocumentRevision', [
                    'Kind' => $kind, 'ResourceId' => $this->id($table, $id), 'StorageId' => $file,
                    'Sha256' => $asset['sha256'], 'CreatedAt' => $date,
                ], $asset['sha256'], locator: ['Id' => $this->uuid($key.'/'.$asset['sha256'])]);
            });
        }
        foreach (['clientes' => 'cliente', 'asesores' => 'asesor'] as $table => $kind) {
            foreach ($this->rows[$table] as $id => $row) {
                if ($row['user_id'] === null) {
                    continue;
                }
                $this->inspect($table, $id, function () use ($table, $kind, $id, $row) {
                    $user = $this->id('users', $row['user_id']);
                    $resource = $this->id($table, $id);
                    $hash = hash('sha256', "$table/$id/{$row['user_id']}");
                    $this->targets['seguridad']->row('vinculos_'.$table, (string) $id, 'profile_bindings', [
                        'UserId' => $user, 'LastOperation' => $this->uuid("$table/$id/vinculo"), 'Hash' => $hash, 'ActivationVersion' => 0,
                    ], $hash, locator: ['Id' => $this->dataset.'|'.$kind.'|'.$resource]);
                });
            }
        }
    }

    private function finance(): void
    {
        $this->batch('finanzas', 'metodos_pago', 'MetodosPago', fn ($r) => [
            ['Nombre' => $r['nombre_metodo'], 'Estado' => $this->enabled($r['estado'])] + $this->timestamps($r, "metodo/{$r['id']}"),
            [], [['Nombre' => $r['nombre_metodo']]],
        ]);
        $this->batch('finanzas', 'cuentas_bancarias', 'CuentasBancarias', fn ($r) => [
            ['Moneda' => $this->accountCurrencies[$r['id']] ?? throw new RuntimeException('Falta moneda de la cuenta.'),
                'Estado' => $this->enabled($r['estado']), 'Banco' => $r['banco'] ?? '', 'NumeroCuenta' => $r['numero_cuenta'] ?? '',
                'Titular' => $r['titular'] ?? '']
            + $this->fields($r, 'tipo descripcion iban proveedor codigo_integracion nombre saldo_inicial', 'pascal')
            + $this->timestamps($r, "cuenta/{$r['id']}"),
        ]);
        $this->batch('finanzas', 'metodo_pago_cuenta_default', 'MethodAccountDefault', fn ($r) => [
            ['MetodoPagoId' => $this->id('metodos_pago', $r['metodo_pago_id']),
                'CuentaBancariaId' => $this->id('cuentas_bancarias', $r['cuenta_bancaria_id'])]
            + $this->timestamps($r, "metodo-cuenta/{$r['id']}"), [], [['MetodoPagoId' => $this->id('metodos_pago', $r['metodo_pago_id'])]],
        ]);
        foreach ($this->rows['notas_ventas'] as $id => $row) {
            $this->inspect('cuentas_venta', $id, function () use ($id, $row) {
                $sale = $this->sales[$id] ?? throw new RuntimeException('Falta una venta conciliada.');
                $target = $this->targets['finanzas'];
                $venta = $this->id('notas_ventas', $id);
                $hash = $this->sourceHash($this->source, 'notas_ventas', $row);
                $previous = isset($this->old['notas_ventas'][$id]) ? $this->sourceHash($this->previous, 'notas_ventas', $this->old['notas_ventas'][$id]) : null;
                $snapshot = [
                    'id' => $venta, 'propiedad_id' => $sale['property'], 'cliente_id' => $sale['client'], 'asesor_id' => $sale['adviser'],
                    'moneda' => $sale['currency'], 'monto_liquido' => $row['monto_liquido'] ?? $row['monto_total'],
                    'cuota_inicial' => $row['cuota_inicial'] ?? 0, 'monto_comision' => $row['monto_comision'] ?? 0,
                    'saldo_credito' => $row['saldo_credito'] ?? 0,
                ] + $this->fields($row, 'fecha tipo_venta monto_total');
                $this->mapped['cuentas_venta'][$id] = $target->row('cuentas_venta', (string) $id, 'CuentasVentas', [
                    'NotaVentaId' => $venta, 'ClienteId' => $sale['client'], 'AsesorId' => $sale['adviser'],
                    'TipoVenta' => $row['tipo_venta'], 'Moneda' => $sale['currency'], 'MontoLiquido' => $snapshot['monto_liquido'],
                    'CuotaInicial' => $snapshot['cuota_inicial'], 'MontoComision' => $snapshot['monto_comision'], 'Estado' => $sale['state'],
                ] + $this->timestamps($row, "cuenta-venta/$id"), $hash, $previous, unique: [['NotaVentaId' => $venta]]);
                $time = $row['updated_at'] ?? $row['created_at'] ?? '1970-01-01 00:00:00';
                $target->row('historia_financiera', (string) $id, 'FinancialOperation', [
                    'State' => $sale['state'] === 'Activa' ? 'Activa' : 'Cerrada', 'VentaId' => $venta, 'PropiedadId' => $sale['property'],
                    'Snapshot' => Snapshot::json($snapshot), 'SnapshotHash' => hash('sha256', Snapshot::json($snapshot)),
                    'ReadSnapshot' => Snapshot::json($sale['document']), 'ActorIssuer' => $this->issuer, 'ActorSubject' => '0',
                    'CustomerName' => $sale['document']['cliente']['nombre_completo'],
                    'AdviserName' => $sale['document']['asesor']['nombre_completo'],
                    'AdviserEmail' => $sale['document']['asesor']['correo'], 'CustomerCi' => $sale['document']['cliente']['ci'], 'UpdatedAt' => $time,
                ], $hash, $previous, ['Id' => $sale['operation']], [['VentaId' => $venta]]);
                if ($sale['state'] === 'Anulada') {
                    $target->row('ventas_anuladas', (string) $id, 'VentasAnuladas', ['NotaVentaId' => $venta]
                        + $this->timestamps($row, "venta-anulada/$id"), $hash, $previous, unique: [['NotaVentaId' => $venta]]);
                } else {
                    $this->registerHistoricalSale($id, $row, $sale, $venta, $hash, $previous);
                }
            });
        }
        $this->batch('finanzas', 'planes_pagos', 'PlanesPago', fn ($r) => [
            ['CuentaVentaId' => $this->id('cuentas_venta', $r['nota_venta_id']), 'TasaMora' => $r['tasa_mora'] ?? 0]
            + $this->fields($r, 'monto numero_cuotas tasa_interes fecha_inicio fecha_final', 'pascal')
            + $this->timestamps($r, "plan/{$r['id']}"), [], [['CuentaVentaId' => $this->id('cuentas_venta', $r['nota_venta_id'])]],
        ]);
        $this->batch('finanzas', 'reprogramaciones', 'Reprogramaciones', fn ($r) => [
            ['PlanPagoId' => $this->id('planes_pagos', $r['plan_pago_id']), 'UsuarioId' => $this->id('users', $r['usuario_id']) ?? 0,
                'SolicitudId' => $this->uuid("reprogramacion/{$r['id']}")]
            + $this->fields($r, 'motivo fecha_reprogramacion cuota_desde saldo_capital_momento nueva_tasa_interes nuevo_numero_cuotas nueva_fecha_inicio', 'pascal')
            + $this->timestamps($r, "reprogramacion/{$r['id']}"),
        ]);
        $this->batch('finanzas', 'cuotas', 'Cuotas', fn ($r) => [
            ['PlanPagoId' => $this->id('planes_pagos', $r['plan_pago_id']), 'ReprogramacionId' => $this->id('reprogramaciones', $r['reprogramacion_id'] ?? null),
                'InteresAcreditado' => $r['interes_acreditado'] ?? 0]
            + $this->fields($r, 'numero_cuota fecha_vencimiento monto_cuota monto_capital monto_interes saldo_capital estado', 'pascal')
            + $this->timestamps($r, "cuota/{$r['id']}"),
        ]);
        $this->batch('finanzas', 'pagos', 'Pagos', fn ($r) => [
            ['CuentaVentaId' => $this->id('cuentas_venta', $r['nota_venta_id']), 'CuotaId' => $this->id('cuotas', $r['cuota_id']),
                'MetodoPagoId' => $this->id('metodos_pago', $r['metodo_pago_id']), 'CuentaId' => $this->id('cuentas_bancarias', $r['cuenta_id'])]
            + $this->fields($r, 'concepto_pago fecha_pago monto estado observaciones', 'pascal')
            + $this->timestamps($r, "pago/{$r['id']}"),
        ]);
        foreach (['ingresos', 'egresos'] as $table) {
            $this->batch('finanzas', $table, ucfirst($table), function ($r) use ($table) {
                $data = ['CuentaVentaId' => $this->id('cuentas_venta', $r['nota_venta_id']), 'UserId' => $this->id('users', $r['user_id']) ?? 0,
                    'CuentaBancariaId' => $this->id('cuentas_bancarias', $r['cuenta_bancaria_id']), 'Moneda' => $this->currency($r['moneda'])]
                    + $this->fields($r, 'fecha concepto categoria monto origen observaciones estado comprobante', 'pascal')
                    + $this->timestamps($r, "$table/{$r['id']}");
                if ($table === 'ingresos') {
                    $data['PagoId'] = $this->id('pagos', $r['pago_id']);
                    $keys = [['PagoId' => $data['PagoId']]];
                } else {
                    $data['AsesorId'] = $this->id('asesores', $r['asesor_id']);
                    $data['Beneficiario'] = $r['beneficiario'];
                    $keys = $r['categoria'] === 'COMISION_ASESOR' ? [['CuentaVentaId' => $data['CuentaVentaId'], 'Categoria' => $r['categoria']]] : [];
                }

                return [$data, [], $keys];
            });
        }
    }

    private function registerHistoricalSale(int $id, array $row, array $sale, int $venta, string $hash, ?string $previous): void
    {
        $time = $row['created_at'] ?? '1970-01-01 00:00:00';
        $reservation = $this->uuid("reserva/$id");
        $this->targets['propiedades']->row('reservas_historicas', (string) $id, 'catalog_reservas', [
            'PropiedadId' => $sale['property'], 'Origin' => 'comercial', 'OperationId' => $sale['operation'],
            'ActorIssuer' => $this->issuer, 'ActorSubject' => '0', 'FencingToken' => 1, 'Estado' => 'Confirmada', 'Vigente' => 1,
            'ExpiresAt' => $time, 'PrecioVenta' => $row['monto_total'], 'Moneda' => $sale['currency'],
            'PriceVersion' => 'importacion-historica', 'CreatedAt' => $time, 'UpdatedAt' => $time,
        ], $hash, $previous, ['Id' => $reservation]);
        $this->targets['comercial']->row('ventas_historicas', (string) $id, 'SaleOperation', [
            'CommandId' => $this->uuid("importacion/$id"), 'Kind' => 'venta', 'PropiedadId' => $sale['property'], 'VentaId' => $venta,
            'State' => 'Completada', 'Step' => 'Terminada', 'ActorIssuer' => $this->issuer, 'ActorSubject' => '0',
            'Payload' => Snapshot::json(['origen' => 'importacion_historica', 'source_id' => $id]), 'PayloadHash' => $hash,
            'Reservation' => Snapshot::json(['reserva_id' => $reservation, 'fencing_token' => 1]),
            'Attempts' => 0, 'CreatedAt' => $time, 'UpdatedAt' => $time, 'NextAttempt' => $time, 'Stamp' => $this->uuid("importacion/$id/stamp"),
        ], $hash, $previous, ['Id' => $sale['operation']]);
        $this->targets['seguridad']->row('ventas_historicas', (string) $id, 'operation_grants', [
            'Kind' => 'venta_importada', 'ResourceId' => $sale['property'], 'PayloadHash' => $hash, 'Dataset' => $this->dataset,
            'UserId' => 0, 'UserVersion' => 0, 'SessionJti' => '', 'State' => 'Importada', 'CreatedAt' => $time, 'UpdatedAt' => $time,
        ], $hash, $previous, ['Id' => $sale['operation']]);
    }

    private function balances(): void
    {
        $paid = [];
        foreach ($this->rows['pagos'] as $row) {
            if ($row['cuota_id'] !== null && $row['estado'] === 'PAGADO' && $row['concepto_pago'] === 'CUOTA') {
                $paid[$row['cuota_id']] = ($paid[$row['cuota_id']] ?? 0) + $this->cents($row['monto']);
            }
        }
        $capital = [];
        foreach ($this->rows['cuotas'] as $id => $quota) {
            $this->inspect('cuotas', $id, function () use (&$capital, $id, $quota, $paid) {
                $plan = $this->rows['planes_pagos'][$quota['plan_pago_id']] ?? throw new RuntimeException('Cuota sin plan.');
                $sale = $plan['nota_venta_id'];
                $currency = $this->currencies[$sale] ?? throw new RuntimeException('Cuota sin moneda de venta.');
                if (in_array($quota['estado'], ['Pendiente', 'Vencida'], true)) {
                    $applied = min($this->cents($quota['monto_cuota']), $paid[$id] ?? 0);
                    $interest = $this->cents($quota['monto_interes']);
                    $remaining = $applied >= $this->cents($quota['monto_cuota']) ? 0
                        : max(0, $this->cents($quota['monto_capital']) - max(0, $applied - $interest));
                    $capital[$sale] = ($capital[$sale] ?? 0) + $remaining;
                    $this->financialTotals['capital_pendiente'][$currency][$quota['estado']] =
                        ($this->financialTotals['capital_pendiente'][$currency][$quota['estado']] ?? 0) + $remaining;
                }
            });
        }
        foreach ($this->rows['notas_ventas'] as $id => $sale) {
            if ($sale['tipo_venta'] === 'CREDITO' && $sale['estado'] !== 'Anulada'
                && $this->cents($sale['saldo_credito'] ?? 0) !== ($capital[$id] ?? 0)) {
                $this->issue('notas_ventas', $id, 'El capital registrado difiere del plan vigente.');
            }
        }
    }

    private function enabled(bool|int|string $state): bool
    {
        return match ($state) {
            true, 1, '1', 'Activo', 'Activa' => true,
            false, 0, '0', 'Inactivo', 'Inactiva' => false,
            default => throw new RuntimeException('Estado activo desconocido.'),
        };
    }

    private function decisions(string $filename): array
    {
        $path = realpath($this->source->directory.'/'.$filename);
        if ($path === false) {
            return [];
        }
        if (! Snapshot::inside($this->source->directory, $path)) {
            throw new RuntimeException('El archivo de decisiones está fuera del paquete.');
        }

        $contents = file_get_contents($path);
        $this->decisionHashes[$filename] = hash('sha256', $contents);

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    private function saleDocument(array $sale, string $currency): array
    {
        $p = $this->rows['propiedades'][$sale['propiedad_id']];
        $sector = $this->rows['sectores_urbanos'][$p['sector_urbano_id']];
        $district = $this->rows['distritos'][$sector['distrito_id']];
        $city = $this->rows['ciudades'][$district['ciudad_id']];
        $owners = array_filter($this->rows['propiedad_propietario'], fn ($link) => $link['propiedad_id'] === $p['id']);
        $property = array_replace($p, [
            'id' => $this->id('propiedades', $p['id']), 'precio_venta' => $this->cents($sale['monto_total']) / 100, 'moneda' => $currency,
            'propietarios' => array_values(array_map(fn ($link) => array_replace($this->rows['propietarios'][$link['propietario_id']],
                ['id' => $this->id('propietarios', $link['propietario_id'])]), $owners)),
            'sector_urbano' => array_replace($sector, ['id' => $this->id('sectores_urbanos', $sector['id']),
                'distrito' => array_replace($district, ['id' => $this->id('distritos', $district['id']),
                    'ciudad' => array_replace($city, ['id' => $this->id('ciudades', $city['id'])])])]),
        ]);

        return [
            'origen_snapshot' => 'datos disponibles al corte de la importación',
            'cliente' => array_replace($this->fields($this->rows['clientes'][$sale['cliente_id']], 'ci lugar_expedicion nombre_completo correo telefono direccion'),
                ['id' => $this->id('clientes', $sale['cliente_id'])]),
            'asesor' => array_replace($this->fields($this->rows['asesores'][$sale['asesor_id']], 'nombre_completo correo telefono direccion'),
                ['id' => $this->id('asesores', $sale['asesor_id'])]),
            'propiedad' => $property,
        ];
    }

    private function document(string $table, array $row, string $column, string $kind): ?string
    {
        if (empty($row[$column])) {
            return null;
        }
        $key = "$table/{$row['id']}/$column";
        $asset = $this->source->asset($key) ?? throw new RuntimeException('El documento no tiene archivo.');
        $this->extension($asset, true);
        $file = str_replace('-', '', $this->uuid($key.'/'.$asset['sha256']));
        $this->copyAsset('documents', $file.'.pdf', $asset);
        $this->documents[$key] = [$table, $row['id'], $kind, $file, $asset, $row['updated_at'] ?? '1970-01-01 00:00:00'];

        return $file;
    }

    private function extension(array $asset, bool $pdf): string
    {
        return match (true) {
            $pdf && $asset['type'] === 'application/pdf' => 'pdf',
            ! $pdf && $asset['type'] === 'image/png' => 'png',
            ! $pdf && $asset['type'] === 'image/jpeg' => 'jpg',
            ! $pdf && $asset['type'] === 'image/gif' => 'gif',
            default => throw new RuntimeException('El tipo del archivo es incompatible con su entidad.'),
        };
    }

    private function copyAsset(string $directory, string $name, array $asset): void
    {
        $base = $this->root.'/'.$directory;
        $path = $base.'/'.$name;
        if ($this->mode === 'apply' && ! is_file($path)) {
            $parent = dirname($path);
            if (! is_dir($parent) && ! mkdir($parent, 0700, true)) {
                throw new RuntimeException('No se pudo preparar el almacenamiento de destino.');
            }
            if (! Snapshot::inside($this->root, realpath($parent) ?: '')) {
                throw new RuntimeException('El almacenamiento de destino está fuera del ensayo.');
            }
            $temp = tempnam($parent, 'f7-');
            try {
                if (! copy($this->source->assetFile($asset['sha256']), $temp) || hash_file('sha256', $temp) !== $asset['sha256']
                    || ! rename($temp, $path)) {
                    throw new RuntimeException('No se pudo publicar el archivo verificado.');
                }
            } finally {
                if (is_file($temp)) {
                    unlink($temp);
                }
            }
        }
        if ($this->mode !== 'plan') {
            if (! is_file($path) || ! Snapshot::inside($this->root, realpath($path) ?: '') || hash_file('sha256', $path) !== $asset['sha256']) {
                throw new RuntimeException('El archivo de destino falta o no coincide.');
            }
            $this->verifiedFiles[$directory.'/'.$name] = true;
        }
    }

    private function batch(string $service, string $entity, string $table, callable $project): void
    {
        $target = $this->targets[$service];
        foreach ($this->rows[$entity] as $id => $row) {
            $this->inspect($entity, $id, function () use ($target, $entity, $id, $row, $table, $project) {
                [$data, $locator, $unique] = array_pad($project($row), 3, []);
                $previous = isset($this->old[$entity][$id]) ? $this->sourceHash($this->previous, $entity, $this->old[$entity][$id]) : null;
                $this->mapped[$entity][$id] = $target->row($entity, (string) $id, $table, $data,
                    $this->sourceHash($this->source, $entity, $row), $previous, $locator, $unique);
            });
        }
        $this->inspect($entity, 'checkpoint', fn () => $target->assertComplete($entity, array_keys($this->rows[$entity])));
    }

    private function sourceHash(Snapshot $source, string $entity, array $row): string
    {
        $prefix = "$entity/{$row['id']}/";
        $assets = array_filter($source->manifest['assets'], fn ($key) => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);

        return hash('sha256', Snapshot::json([$row, $assets]));
    }

    private function removeRetired(): void
    {
        $policies = [
            'rol_permiso_usuario' => ['seguridad', null], 'caracteristica_propiedad' => ['propiedades', null],
            'propiedad_propietario' => ['propiedades', null], 'users' => ['seguridad', ['estado' => 0]],
            'imagenes_propiedades' => ['propiedades', ['Publicada' => 0, 'es_principal' => 0]],
        ];
        foreach ($this->old as $entity => $rows) {
            foreach (array_diff_key($rows, $this->rows[$entity]) as $id => $row) {
                $this->inspect($entity, $id, function () use ($policies, $entity, $id, $row) {
                    if (! array_key_exists($entity, $policies)) {
                        throw new RuntimeException('La baja requiere una resolución específica del dominio.');
                    }
                    [$target, $retained] = $policies[$entity];
                    $this->targets[$target]->remove($entity, (string) $id, $this->sourceHash($this->previous, $entity, $row), $retained);
                });
            }
        }
    }

    private function fields(array $row, string $names, string $case = 'snake'): array
    {
        $result = [];
        foreach (explode(' ', $names) as $name) {
            $destination = $case === 'pascal' ? str_replace(' ', '', ucwords(str_replace('_', ' ', $name))) : $name;
            $result[$destination] = $row[$name] ?? null;
        }

        return $result;
    }

    private function id(string $entity, int|string|null $source): int|string|null
    {
        return $source === null ? null : ($this->mapped[$entity][$source] ?? throw new RuntimeException("Falta una equivalencia en $entity."));
    }

    private function timestamps(array $row, string $key): array
    {
        return ['CreatedAt' => $row['created_at'] ?? '1970-01-01 00:00:00',
            'UpdatedAt' => $row['updated_at'] ?? $row['created_at'] ?? '1970-01-01 00:00:00', 'Version' => $this->uuid($key.'/version')];
    }

    private function uuid(string $key): string
    {
        $hex = hash('sha256', $this->dataset."\0".$key);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3).'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    private function currency(string $currency): string
    {
        return match ($currency) {
            'Bs', 'BOB' => 'BOB', '$', 'USD' => 'USD',
            default => throw new RuntimeException('Moneda no reconocida.'),
        };
    }

    private function addTotal(string $entity, string $currency, string $state, string|int $value): void
    {
        $this->financialTotals[$entity][$currency][$state] = ($this->financialTotals[$entity][$currency][$state] ?? 0) + $this->cents($value);
    }

    private function cents(string|int $value): int
    {
        if (! preg_match('/^(-?)(\d{1,14})(?:\.(\d{1,2}))?$/D', (string) $value, $parts)) {
            throw new RuntimeException('Precisión monetaria no admitida.');
        }

        return ((int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0')) * ($parts[1] === '-' ? -1 : 1);
    }

    private function inspect(string $entity, int|string $id, callable $action): void
    {
        try {
            $action();
        } catch (PDOException $error) {
            $this->issue($entity, $id, 'Restricción o error SQL ('.$error->getCode().').');
        } catch (RuntimeException $error) {
            $this->issue($entity, $id, $error->getMessage());
        }
    }

    private function issue(string $entity, int|string $id, string $reason): void
    {
        $this->issues[] = compact('entity', 'id', 'reason');
    }
}

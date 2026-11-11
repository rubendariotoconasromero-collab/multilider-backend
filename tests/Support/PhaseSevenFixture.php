<?php

use App\DataMigration\Connections;
use App\Models\NotaVenta;
use App\Models\PlanPago;
use App\Models\Propiedad;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\BusinessFixtures;

$root = Connections::root(dirname(__DIR__, 2));
if ($action === 'phase7-delta') {
    DB::table('ciudades')->insert(['nombre' => 'Ciudad posterior al corte', 'departamento' => 'Ensayo', 'estado' => 1]);
    DB::table('ciudades')->where('id', 1)->update(['nombre' => 'Ciudad revisada']);
    $seller = User::where('correo', 'sales-only@example.test')->firstOrFail();
    DB::table('rol_permiso_usuario')->where('user_id', $seller->id)->delete();
    $seller->update(['estado' => false]);
    DB::table('imagenes_propiedades')->delete();
    echo "F7 delta fixture: alta, cambio, desactivación, retiro de permiso e imagen.\n";

    return;
}

$identity = identityDb();
foreach (['rol_permiso_usuario', 'rol_permiso', 'permisos', 'roles', 'users'] as $table) {
    $identity->exec("DELETE FROM $table");
}
$identity->exec("INSERT INTO users(id,nombre,correo,password,estado) VALUES(900,'Identidad de control','native-f7@example.test','inactive-fixture',0)");
$operator = User::where('correo', 'operator@example.test')->firstOrFail();
$customer = DB::table('clientes')->first();
$adviser = DB::table('asesores')->first();
$role = DB::table('roles')->insertGetId(['nombre' => 'Cliente', 'estado' => 1]);
$permission = DB::table('permisos')->insertGetId(['nombre' => 'acceso_cartera_propia', 'estado' => 1]);
$binding = DB::table('rol_permiso')->insertGetId(['rol_id' => $role, 'permiso_id' => $permission]);
DB::table('rol_permiso_usuario')->insert(['user_id' => $customer->user_id, 'rol_permiso_id' => $binding]);
$property = Propiedad::where('codigo', 'LOCAL-ONLY')->firstOrFail();
$fixture = new class
{
    use BusinessFixtures;
    public function cash(): Propiedad
    {
        return $this->property('F7-CASH');
    }
};
$cashProperty = $fixture->cash();
$cashProperty->update(['estado' => 'Vendido', 'moneda' => 'USD']);
$property->update(['estado' => 'Vendido', 'es_destacado' => true]);
$baseSale = ['cliente_id' => $customer->id, 'asesor_id' => $adviser->id, 'estado' => 'Completada', 'fecha' => '2026-10-29',
    'monto_total' => 200, 'descuento' => 0, 'monto_liquido' => 200, 'cuota_inicial' => 0, 'monto_comision' => 6];
$credit = NotaVenta::create($baseSale + ['propiedad_id' => $property->id, 'tipo_venta' => 'CREDITO', 'saldo_credito' => 195]);
$cash = NotaVenta::create($baseSale + ['propiedad_id' => $cashProperty->id, 'tipo_venta' => 'CONTADO', 'saldo_credito' => 0]);
$plan = PlanPago::create(['nota_venta_id' => $credit->id, 'monto' => 200, 'numero_cuotas' => 2, 'tasa_interes' => 10,
    'tasa_mora' => 2, 'fecha_inicio' => '2026-10-31', 'fecha_final' => '2026-12-01', 'plazo' => '2 meses']);
$revision = DB::table('reprogramaciones')->insertGetId(['plan_pago_id' => $plan->id, 'usuario_id' => $operator->id,
    'motivo' => 'Revisión ficticia', 'fecha_reprogramacion' => '2026-11-03', 'cuota_desde' => 2, 'saldo_capital_momento' => 100,
    'nueva_tasa_interes' => 10, 'nuevo_numero_cuotas' => 1, 'nueva_fecha_inicio' => '2026-12-01', 'created_at' => now(), 'updated_at' => now()]);
$quota = ['monto_cuota' => 110, 'monto_interes' => 10, 'monto_capital' => 100];
$first = $plan->cuotas()->create($quota + ['numero_cuota' => 1, 'fecha_vencimiento' => '2026-10-31', 'saldo_capital' => 100, 'estado' => 'Vencida']);
$plan->cuotas()->create($quota + ['numero_cuota' => 2, 'fecha_vencimiento' => '2026-11-30', 'saldo_capital' => 0, 'estado' => 'Reemplazada']);
$plan->cuotas()->create($quota + ['numero_cuota' => 2, 'fecha_vencimiento' => '2026-12-01', 'saldo_capital' => 0, 'estado' => 'Pendiente', 'reprogramacion_id' => $revision]);
$payment = DB::table('pagos')->insertGetId(['nota_venta_id' => $credit->id, 'cuota_id' => $first->id, 'cuenta_id' => 1,
    'metodo_pago_id' => 1, 'concepto_pago' => 'CUOTA', 'estado' => 'PAGADO', 'monto' => 15, 'fecha_pago' => '2026-11-05']);
DB::table('ingresos')->insert(['pago_id' => $payment, 'nota_venta_id' => $credit->id, 'cuenta_bancaria_id' => 1,
    'user_id' => $operator->id, 'monto' => 15, 'moneda' => 'Bs', 'fecha' => '2026-11-05', 'categoria' => 'CUOTA',
    'concepto' => 'Pago parcial F7', 'origen' => 'AUTOMATICO', 'estado' => 'CONFIRMADO']);
foreach ([[$credit, 1, 'Bs'], [$cash, 2, '$']] as [$sale, $account, $currency]) {
    DB::table('egresos')->insert(['nota_venta_id' => $sale->id, 'cuenta_bancaria_id' => $account, 'asesor_id' => $adviser->id,
        'user_id' => $operator->id, 'monto' => 6, 'moneda' => $currency, 'fecha' => '2026-10-29',
        'categoria' => 'COMISION_ASESOR', 'concepto' => 'Comisión F7', 'origen' => 'AUTOMATICO', 'estado' => 'PENDIENTE']);
}
DB::table('metodo_pago_cuenta_default')->insert(['mi_empresa_id' => 1, 'metodo_pago_id' => 1, 'cuenta_bancaria_id' => 1]);
$contract = DB::table('contratos')->insertGetId(['nota_venta_id' => $credit->id, 'codigo_contrato' => 'F7-FIRMADO',
    'fecha_emision' => '2026-10-29', 'fecha_firma' => '2026-10-30', 'tipo_venta' => 'CREDITO',
    'url_doc' => '/storage/fixtures/f7-contrato.pdf', 'estado' => 'Firmado']);
DB::table('contratos')->insert(['nota_venta_id' => $cash->id, 'codigo_contrato' => 'F7-CONTADO',
    'fecha_emision' => '2026-10-29', 'tipo_venta' => 'CONTADO', 'estado' => 'Pendiente']);
DB::table('entregas')->insert(['contrato_id' => $contract, 'fecha_programada' => '2026-11-01', 'estado' => 'Pendiente',
    'url_acta' => '/storage/fixtures/f7-acta.pdf']);
DB::table('imagenes_propiedades')->insert(['propiedad_id' => $property->id, 'url' => '/storage/fixtures/f7.png', 'es_principal' => 1]);
DB::table('asesores')->where('id', $adviser->id)->update(['foto' => '/storage/fixtures/f7.png']);
$files = $root.'/source-files/fixtures';
mkdir($files, 0700, true);
$pdf = \App\Services\PdfFactory::make();
$pdf->WriteHTML('<p>Contrato sintético F7.</p>');
file_put_contents($files.'/f7-contrato.pdf', $pdf->Output('', 'S'));
$pdf = \App\Services\PdfFactory::make();
$pdf->WriteHTML('<p>Acta sintética F7.</p>');
file_put_contents($files.'/f7-acta.pdf', $pdf->Output('', 'S'));
file_put_contents($files.'/f7.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2ioAAAAASUVORK5CYII='));
$localFiles = storage_path('app/public/fixtures');
if (! is_dir($localFiles)) {
    mkdir($localFiles, 0700, true);
}
foreach (['f7-contrato.pdf', 'f7-acta.pdf', 'f7.png'] as $name) {
    copy($files.'/'.$name, $localFiles.'/'.$name);
}
echo "F7 fixture prepared with two sales, two currencies, historical revision and private documents.\n";

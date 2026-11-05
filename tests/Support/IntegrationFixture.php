<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\BusinessFixtures;
use Tests\Support\IsolatedDatabaseGuard;

// Explicit CLI fixture tool. No application startup seeding and no production connections.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->useStoragePath(__DIR__.'/../../storage/framework/testing/app');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, 'Integration fixture failed: '.$error->getMessage().PHP_EOL);
    exit(1);
});
if (getenv('MULTILIDER_TEST_ISOLATED') !== '1') {
    throw new RuntimeException('Isolated runner required.');
}
IsolatedDatabaseGuard::check($app);
$action = $argv[1] ?? '';
function identityDb(): PDO
{
    if (getenv('MULTILIDER_IDENTITY_DATABASE') !== 'multilider_identity_tests_isolated') {
        throw new RuntimeException('Unsafe identity database.');
    }

    return new PDO('mysql:host=127.0.0.1;port='.getenv('MULTILIDER_TEST_PORT').';dbname=multilider_identity_tests_isolated;charset=utf8mb4', getenv('MULTILIDER_IDENTITY_USER'), getenv('MULTILIDER_IDENTITY_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
if ($action === 'prepare') {
    Artisan::call('migrate:fresh', ['--force' => true]);
    $fixture = new class
    {
        use BusinessFixtures;

        public function seed(): void
        {
            $user = $this->user();
            $user->update(['correo' => 'operator@example.test']);
            foreach (['acceso_usuarios', 'acceso_roles', 'acceso_permisos', 'acceso_propiedades', 'acceso_ciudades', 'acceso_zonas', 'acceso_caracteristicas', 'acceso_propietarios', 'acceso_ventas'] as $permission) {
                $this->grant($user, $permission);
            }
            $property = $this->property('LOCAL-ONLY');
            file_put_contents(__DIR__.'/../../storage/framework/testing/local-sale-fixture.json', json_encode($this->salePayload($property)));
        }
    };
    $fixture->seed();
    $pdo = identityDb();
    // Reproduce a populated pre-F3 schema using its real Laravel definitions, not EnsureCreated.
    foreach (['users', 'roles', 'permisos', 'rol_permiso', 'rol_permiso_usuario'] as $table) {
        $definition = (array) DB::selectOne('SHOW CREATE TABLE `'.$table.'`');
        $pdo->exec(preg_replace('/ AUTO_INCREMENT=\d+/', '', array_values($definition)[1]));
    }
    $hash = Hash::make('Test-only-Password-42!');
    $query = $pdo->prepare('INSERT INTO users(nombre,correo,password,estado) VALUES (?,?,?,1)');
    $query->execute(['Operador remoto ficticio', 'operator@example.test', $hash]);
    $pdo->exec("INSERT INTO roles(nombre,estado) VALUES('Operador',1)");
    foreach (['acceso_usuarios', 'acceso_roles', 'acceso_permisos', 'acceso_propiedades', 'acceso_ciudades', 'acceso_zonas', 'acceso_caracteristicas', 'acceso_propietarios', 'acceso_ventas'] as $permission) {
        $q = $pdo->prepare('INSERT INTO permisos(nombre,estado) VALUES(?,1)');
        $q->execute([$permission]);
        $perm = $pdo->lastInsertId();
        $pdo->exec("INSERT INTO rol_permiso(rol_id,permiso_id) VALUES(1,$perm)");
        $link = $pdo->lastInsertId();
        $pdo->exec("INSERT INTO rol_permiso_usuario(user_id,rol_permiso_id) VALUES(1,$link)");
    }
    echo "Isolated local and pre-F3 identity fixtures prepared.\n";
} elseif ($action === 'phase5') {
    $pdo = identityDb();
    $localGrants = new class { use BusinessFixtures; public function add(string $name): void { $this->grant(\App\Models\User::where('correo', 'operator@example.test')->firstOrFail(), $name); } };
    foreach (['acceso_clientes', 'acceso_asesores', 'acceso_historial_ventas', 'acceso_contratos', 'acceso_entregas', 'acceso_pagos', 'acceso_ingresos', 'acceso_egresos'] as $name) {
        $localGrants->add($name);
        $q = $pdo->prepare('INSERT INTO permisos(nombre,estado) VALUES(?,1)'); $q->execute([$name]); $permission = $pdo->lastInsertId();
        $pdo->exec("INSERT INTO rol_permiso(rol_id,permiso_id) VALUES(1,$permission)"); $link = $pdo->lastInsertId();
        $pdo->exec("INSERT INTO rol_permiso_usuario(user_id,rol_permiso_id) VALUES(1,$link)");
    }
    $pdo->exec("INSERT INTO roles(nombre,estado) VALUES('Cliente',1),('Asesor de Ventas',1)");
    echo "F5 role and permission fixtures prepared.\n";
} elseif ($action === 'verify-migration') {
    $pdo = identityDb();
    $user = $pdo->query('SELECT * FROM users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if (! $user || $user['correo'] !== 'operator@example.test' || ! Hash::check('Test-only-Password-42!', $user['password']) || (int) $user['auth_version'] !== 0) {
        throw new RuntimeException('Existing identity changed during migration.');
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM rol_permiso_usuario')->fetchColumn() !== 9) {
        throw new RuntimeException('Assignments changed.');
    }
    echo "Additive MySQL migration preserved user hash and nine assignments.\n";
} else {
    throw new RuntimeException('Unknown fixture operation.');
}

<?php

namespace Tests\Support;

use App\Models\Asesor;
use App\Models\Cliente;
use App\Models\Propiedad;
use App\Models\User;
use Illuminate\Support\Facades\DB;

trait BusinessFixtures
{
    protected function user(string $email = 'operador@example.test'): User
    {
        return User::create(['nombre' => 'Usuario de prueba', 'correo' => $email,
            'password' => 'Test-only-Password-42!', 'estado' => true]);
    }

    protected function grant(User $user, string $permission): void
    {
        $role = DB::table('roles')->insertGetId(['nombre' => 'Rol '.$permission]);
        $perm = DB::table('permisos')->insertGetId(['nombre' => $permission]);
        $link = DB::table('rol_permiso')->insertGetId(['rol_id' => $role, 'permiso_id' => $perm]);
        DB::table('rol_permiso_usuario')->insert(['user_id' => $user->id, 'rol_permiso_id' => $link]);
    }

    protected function property(string $code = 'TEST-001'): Propiedad
    {
        $city = DB::table('ciudades')->insertGetId(['nombre' => 'Ciudad ficticia', 'departamento' => 'Prueba', 'estado' => 1]);
        $district = DB::table('distritos')->insertGetId(['nombre' => 'Distrito ficticio', 'ciudad_id' => $city, 'estado' => 1]);
        $sector = DB::table('sectores_urbanos')->insertGetId(['nombre' => 'Sector ficticio', 'distrito_id' => $district, 'tipo' => 'Barrio', 'estado' => 1]);
        $owner = DB::table('propietarios')->insertGetId(['nombre_completo' => 'Propietario de prueba', 'ci' => $code, 'estado' => 1]);
        $property = Propiedad::create(['codigo' => $code, 'sector_urbano_id' => $sector, 'tipo' => 'Lote',
            'precio_venta' => 10000, 'moneda' => 'BOB', 'superficie_m2' => 200, 'estado' => 'Disponible', 'activo' => 1]);
        $property->propietarios()->attach($owner);

        return $property;
    }

    protected function salePayload(Propiedad $property, bool $credit = false): array
    {
        $advisor = Asesor::create(['user_id' => $this->user('asesor@example.test')->id,
            'nombre_completo' => 'Asesor ficticio', 'porcentaje_comision' => 3, 'estado' => 1]);
        $client = Cliente::create(['user_id' => $this->user('cliente@example.test')->id,
            'nombre_completo' => 'Cliente ficticio', 'ci' => 'TEST-CI', 'estado' => 1]);

        return ['asesor_id' => $advisor->id, 'cliente_id' => $client->id, 'propiedad_id' => $property->id,
            'fecha' => '2026-11-04', 'monto_total' => 10000, 'tipo_venta' => $credit ? 'CREDITO' : 'CONTADO',
            'monto_liquido' => 9500, 'descuento' => 500, 'cuota_inicial' => 2000, 'saldo_credito' => 8000,
            'numero_cuotas' => 4, 'tasa_interes' => 0, 'tasa_mora' => 2, 'fecha_inicio_pago' => '2026-12-04'];
    }
}

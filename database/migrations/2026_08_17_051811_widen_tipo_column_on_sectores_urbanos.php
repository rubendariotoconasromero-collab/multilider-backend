<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `tipo` era un ENUM fijo cuando esta tabla se llenaba solo desde el CRUD de Laravel.
     * Ahora es una copia local del microservicio de Propiedades (.NET), donde `tipo` es
     * texto libre, así que la columna deja de poder restringirse a valores fijos.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE sectores_urbanos MODIFY tipo VARCHAR(50) NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE sectores_urbanos MODIFY tipo ENUM('Barrio','Urbanización','Condominio') NOT NULL DEFAULT 'Barrio'");
    }
};

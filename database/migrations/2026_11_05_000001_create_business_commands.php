<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_command_mutex', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); });
        DB::table('business_command_mutex')->insert(['id' => 1]);
        Schema::create('business_commands', function (Blueprint $table) {
            $table->char('key', 64)->primary();
            $table->uuid('operation_id')->index();
            $table->uuid('command_id');
            $table->unsignedBigInteger('actor_id')->index();
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('status');
            $table->longText('result');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        throw new RuntimeException('Los recibos de negocio requieren recuperación compatible; no se eliminan con down.');
    }
};

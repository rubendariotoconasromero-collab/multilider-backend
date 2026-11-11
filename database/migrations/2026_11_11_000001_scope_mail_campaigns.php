<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campanas_correo', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('origen', 16)->default('local');
            $table->string('dataset', 64)->default('default');
            $table->string('actor_issuer')->nullable();
            $table->string('actor_subject')->nullable();
            $table->string('actor_name')->nullable();
            $table->uuid('request_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->unsignedInteger('total_inciertos')->default(0);
            $table->unique(['origen', 'dataset', 'request_key'], 'campanas_request_scope');
        });
        Schema::create('campana_destinatarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campana_id')->constrained('campanas_correo')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('correo');
            $table->string('estado', 16)->default('pendiente');
            $table->timestamps();
            $table->unique(['campana_id', 'correo']);
            $table->index(['campana_id', 'estado', 'id']);
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('campanas_correo')->whereNotNull('request_key')->exists()) {
            throw new \RuntimeException('Conserva el esquema de campañas o restaura una copia anterior a la fase 6.');
        }
        Schema::dropIfExists('campana_destinatarios');
        Schema::table('campanas_correo', function (Blueprint $table) {
            $table->dropUnique('campanas_request_scope');
            $table->dropColumn(['origen', 'dataset', 'actor_issuer', 'actor_subject', 'actor_name', 'request_key', 'request_hash', 'total_inciertos']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('correo_envios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('origen', 16);
            $table->string('dataset', 64);
            $table->string('actor_kind', 16);
            $table->string('actor_issuer')->default('');
            $table->string('actor_subject', 128);
            $table->string('actor_name');
            $table->string('modulo', 16);
            $table->string('recurso', 64)->nullable();
            $table->unsignedBigInteger('campana_id')->nullable()->index();
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->string('remitente');
            $table->string('asunto');
            $table->text('mensaje')->nullable();
            $table->json('destinatarios');
            $table->string('estado', 24)->default('pendiente')->index();
            $table->timestamps();
            $table->unique(['origen', 'dataset', 'modulo', 'request_key'], 'correo_solicitud_unica');
            $table->index(['origen', 'dataset', 'actor_kind', 'actor_subject'], 'correo_actor');
        });
        Schema::create('correo_adjuntos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nombre');
            $table->string('mime', 128);
            $table->unsignedBigInteger('bytes');
            $table->string('sha256', 64);
            $table->string('ruta');
            $table->timestamps();
        });
        Schema::create('correo_envio_adjuntos', function (Blueprint $table) {
            $table->foreignUuid('envio_id')->constrained('correo_envios')->cascadeOnDelete();
            $table->foreignUuid('adjunto_id')->constrained('correo_adjuntos')->restrictOnDelete();
            $table->primary(['envio_id', 'adjunto_id']);
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('correo_envios')->exists()) {
            throw new RuntimeException('Conserva el archivo documental o restaura conjuntamente la base y sus archivos privados.');
        }
        Schema::dropIfExists('correo_envio_adjuntos');
        Schema::dropIfExists('correo_adjuntos');
        Schema::dropIfExists('correo_envios');
    }
};

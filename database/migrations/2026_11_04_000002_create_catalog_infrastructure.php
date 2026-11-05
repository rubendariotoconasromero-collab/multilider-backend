<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imagenes_propiedades', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->unique();
            $table->string('storage_key', 255)->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('content_type', 50)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('publicada')->default(true);
        });
        Schema::create('catalog_mutex', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('catalog_mutex')->insert(['id' => 1]);
        Schema::create('catalog_commands', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->string('owner', 64);
            $table->uuid('command_id');
            $table->string('request_hash', 64);
            $table->string('permission', 64);
            $table->unsignedSmallInteger('status');
            $table->longText('result');
            $table->string('etag')->nullable();
            $table->timestamps();
            $table->unique(['owner', 'command_id']);
        });
        Schema::create('catalog_media_cleanup', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->string('disk', 10)->default('public');
            $table->timestamps();
        });
        Schema::create('catalog_identity_maps', function (Blueprint $table) {
            $table->collation = 'utf8mb4_bin';
            $table->id();
            $table->string('dataset', 100);
            $table->string('entity', 50);
            $table->string('source_id', 100);
            $table->string('target_service', 50);
            $table->string('target_id', 100);
            $table->string('review_status')->default('pending');
            $table->string('provenance');
            $table->timestamps();
            $table->unique(['dataset', 'entity', 'source_id', 'target_service'], 'catalog_map_source');
            $table->unique(['dataset', 'entity', 'target_service', 'target_id'], 'catalog_map_target');
        });
    }

    public function down(): void
    {
        Schema::table('imagenes_propiedades', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'storage_key', 'content_hash', 'content_type', 'size', 'publicada']);
        });
        Schema::dropIfExists('catalog_identity_maps');
        Schema::dropIfExists('catalog_media_cleanup');
        Schema::dropIfExists('catalog_commands');
        Schema::dropIfExists('catalog_mutex');
    }
};

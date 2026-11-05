<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_test_orders', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('reference', 80)->unique(); $t->char('request_hash', 64);
            $t->unsignedBigInteger('cliente_id'); $t->string('state', 32); $t->decimal('amount', 18, 2);
            $t->string('currency', 3); $t->json('selection'); $t->string('provider_id', 255)->nullable()->unique();
            $t->text('payment_url')->nullable(); $t->text('qr_url')->nullable(); $t->timestamps(); $t->index(['state', 'updated_at']);
        });
        Schema::create('payment_test_inbox', function (Blueprint $t) {
            $t->char('id', 64)->primary(); $t->string('reference', 255)->nullable(); $t->string('provider_id', 255)->nullable();
            $t->string('state', 32)->default('Pendiente'); $t->timestamps();
        });
    }
    public function down(): void { throw new RuntimeException('Las órdenes y recibos externos requieren recuperación compatible.'); }
};

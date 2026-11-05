<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('payment_test_obligations', function (Blueprint $table) {
            $table->uuid('order_id'); $table->unsignedBigInteger('venta_id')->index();
            $table->primary(['order_id', 'venta_id']);
            $table->foreign('order_id')->references('id')->on('payment_test_orders')->restrictOnDelete();
        });
    }
    public function down(): void { throw new RuntimeException('Las órdenes externas y sus vínculos se conservan para conciliación; usa restauración coordinada.'); }
};

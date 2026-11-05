<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('cuotas', fn (Blueprint $table) => $table->decimal('interes_acreditado', 18, 2)->default(0));
    }
    public function down(): void { throw new RuntimeException('Conserva el crédito aplicado a intereses y restaura el conjunto de bases si corresponde.'); }
};

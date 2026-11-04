<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_identities', function (Blueprint $table) {
            $table->id();
            $table->string('issuer', 255)->collation('utf8mb4_bin');
            $table->string('subject', 191)->collation('utf8mb4_bin');
            $table->foreignId('local_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provenance')->nullable();
            $table->string('review_status')->default('pending');
            $table->timestamps();
            $table->unique(['issuer', 'subject']);
        });
        Schema::create('identity_revocations', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('credential')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_revocations');
        Schema::dropIfExists('external_identities');
    }
};

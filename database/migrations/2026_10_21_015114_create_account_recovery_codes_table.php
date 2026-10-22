<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One-time recovery codes for the Super Admin (Forgot Password -> Use a
// recovery code). bcrypt-hashed, so they keep working if APP_KEY changes;
// used_at is set the moment one is used.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_recovery_codes');
    }
};

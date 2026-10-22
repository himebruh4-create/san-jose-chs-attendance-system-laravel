<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Super Admin's 3 security questions (Forgot Password), each picked
// from App\Support\SecurityQuestions::LIST, answers bcrypt-hashed after
// normalizing. Replaces the single free-text accounts.security_question,
// which is left in place but no longer used.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_security_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedTinyInteger('position');
            $table->string('question_key', 40);
            $table->string('answer_hash');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['account_id', 'position']);
            $table->unique(['account_id', 'question_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_security_questions');
    }
};

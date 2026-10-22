<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Set after 3 Forgot Password lockouts within 24 hours: the account's
// security questions stop working until a recovery code or the server
// reset command is used (App\Support\AccountRecovery).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('security_questions_disabled_at')->nullable()->after('security_answer_hash');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('security_questions_disabled_at');
        });
    }
};

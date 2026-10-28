<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per login (App\Support\ActiveSessions). An account's active
// session is its row with neither replaced_at nor ended_at. A row replaced
// by a newer login keeps can_reclaim = true until its owner answers the
// "signed in on another device" prompt (countdown from notified_at); a row
// replaced by "Stay logged in" never can, so two devices cannot keep
// signing each other out.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            // The Laravel session ID at login, for reference. The row is found
            // through its id kept in the (server-side) session data instead,
            // which survives a session ID regeneration.
            $table->string('session_id', 255);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->dateTime('logged_in_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('replaced_at')->nullable();
            $table->unsignedBigInteger('replaced_by_id')->nullable();
            $table->boolean('can_reclaim')->default(false);
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('ended_reason', 30)->nullable();
            $table->index(['account_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_sessions');
    }
};

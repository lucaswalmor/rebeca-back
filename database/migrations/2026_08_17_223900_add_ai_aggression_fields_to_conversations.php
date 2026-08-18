<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('ai_aggression_warned_at')->nullable()->after('ai_pending_message_id');
            $table->timestamp('ai_blocked_at')->nullable()->after('ai_aggression_warned_at');
            $table->string('ai_blocked_reason')->nullable()->after('ai_blocked_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['ai_aggression_warned_at', 'ai_blocked_at', 'ai_blocked_reason']);
        });
    }
};

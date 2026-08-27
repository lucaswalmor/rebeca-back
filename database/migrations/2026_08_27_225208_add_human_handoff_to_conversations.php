<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('ai_human_handoff_at')->nullable()->after('ai_blocked_reason');
            $table->timestamp('ai_human_handoff_notified_at')->nullable()->after('ai_human_handoff_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['ai_human_handoff_at', 'ai_human_handoff_notified_at']);
        });
    }
};

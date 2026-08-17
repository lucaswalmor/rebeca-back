<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(false)->after('subscriber_cleared_at');
            $table->timestamp('last_human_admin_at')->nullable()->after('ai_enabled');
            $table->unsignedBigInteger('ai_pending_message_id')->nullable()->after('last_human_admin_at');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('sent_by_ai')->default(false)->after('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['ai_enabled', 'last_human_admin_at', 'ai_pending_message_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('sent_by_ai');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_settings', function (Blueprint $table) {
            $table->boolean('quiet_hours_enabled')->default(true)->after('takeover_minutes');
            $table->string('quiet_hours_start', 5)->default('02:00')->after('quiet_hours_enabled');
            $table->string('quiet_hours_end', 5)->default('11:00')->after('quiet_hours_start');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_settings', function (Blueprint $table) {
            $table->dropColumn(['quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_new_chat_message_email')->default(true)->after('notify_new_posts_email');
            $table->boolean('notify_live_email')->default(true)->after('notify_new_chat_message_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'notify_new_chat_message_email',
                'notify_live_email',
            ]);
        });
    }
};

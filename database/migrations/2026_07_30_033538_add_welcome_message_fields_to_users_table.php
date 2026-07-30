<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('welcome_titulo', 200)->nullable()->after('chat_wallpaper_mobile');
            $table->text('welcome_body')->nullable()->after('welcome_titulo');
            $table->string('welcome_image_url', 500)->nullable()->after('welcome_body');
            $table->string('welcome_video_url', 500)->nullable()->after('welcome_image_url');
            $table->string('welcome_audio_url', 500)->nullable()->after('welcome_video_url');
            $table->unsignedSmallInteger('welcome_audio_duration')->nullable()->after('welcome_audio_url');
            $table->timestamp('chat_welcome_sent_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'welcome_titulo',
                'welcome_body',
                'welcome_image_url',
                'welcome_video_url',
                'welcome_audio_url',
                'welcome_audio_duration',
                'chat_welcome_sent_at',
            ]);
        });
    }
};

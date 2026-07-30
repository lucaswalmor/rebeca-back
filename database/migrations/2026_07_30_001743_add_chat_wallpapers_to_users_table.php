<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('chat_wallpaper_desktop', 500)->nullable()->after('path_img_avatar');
            $table->string('chat_wallpaper_mobile', 500)->nullable()->after('chat_wallpaper_desktop');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['chat_wallpaper_desktop', 'chat_wallpaper_mobile']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('valor_pacote_audio_chat', 10, 2)->nullable()->after('valor_pacote_midia_chat');
            $table->unsignedInteger('chat_audio_credits')->default(0)->after('chat_media_credits');
        });

        Schema::table('chat_media_purchases', function (Blueprint $table) {
            $table->string('package_type', 20)->default('media')->after('user_id');
            $table->unsignedInteger('quantity')->default(1)->after('credits');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['valor_pacote_audio_chat', 'chat_audio_credits']);
        });

        Schema::table('chat_media_purchases', function (Blueprint $table) {
            $table->dropColumn(['package_type', 'quantity']);
        });
    }
};

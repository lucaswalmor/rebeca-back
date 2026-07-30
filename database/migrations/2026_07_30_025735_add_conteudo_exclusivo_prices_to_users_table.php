<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('valor_imagem_exclusiva_chat', 10, 2)->nullable()->after('valor_pacote_audio_chat');
            $table->decimal('valor_video_exclusivo_chat', 10, 2)->nullable()->after('valor_imagem_exclusiva_chat');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['valor_imagem_exclusiva_chat', 'valor_video_exclusivo_chat']);
        });
    }
};

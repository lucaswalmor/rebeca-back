<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('valor_pacote_midia_chat', 10, 2)->nullable()->after('valor_desconto_semestral');
            $table->unsignedInteger('chat_media_credits')->default(0)->after('valor_pacote_midia_chat');
            $table->timestamp('last_seen_at')->nullable()->after('chat_media_credits');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['valor_pacote_midia_chat', 'chat_media_credits', 'last_seen_at']);
        });
    }
};

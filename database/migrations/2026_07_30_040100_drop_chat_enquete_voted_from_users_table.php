<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'chat_enquete_voted')) {
                $table->dropColumn('chat_enquete_voted');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'chat_enquete_voted')) {
                $table->boolean('chat_enquete_voted')->nullable()->after('valor_desconto_semestral');
            }
        });
    }
};

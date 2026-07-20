<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->boolean('is_preview')->default(false)->after('ordem');
        });

        // Todos os posts passam a ser exclusivos (conteúdo para assinantes)
        DB::table('posts')->update(['tipo_post' => 2]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->dropColumn('is_preview');
        });
    }
};

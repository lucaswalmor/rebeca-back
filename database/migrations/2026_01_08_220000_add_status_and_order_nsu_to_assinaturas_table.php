<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrationss.
     */
    public function up(): void
    {
        Schema::table('assinaturas', function (Blueprint $table) {
            $table->enum('status', ['pendente', 'aprovado', 'recusado'])->default('pendente')->after('tipo_assinatura');
            $table->string('order_nsu')->nullable()->after('status');
            $table->string('link_pagamento')->nullable()->after('order_nsu');
            $table->decimal('valor', 10, 2)->nullable()->after('link_pagamento');
            $table->string('plano')->nullable()->after('valor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assinaturas', function (Blueprint $table) {
            $table->dropColumn(['status', 'order_nsu', 'link_pagamento', 'valor', 'plano']);
        });
    }
};

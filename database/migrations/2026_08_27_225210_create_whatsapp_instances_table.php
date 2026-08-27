<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('nome_instancia')->unique();
            $table->string('instance_id')->nullable();
            $table->string('status', 30)->default('pendente');
            $table->string('numero')->nullable();
            $table->string('notify_number')->nullable();
            $table->text('qrcode_base64')->nullable();
            $table->boolean('webhook_configurado')->default(false);
            $table->timestamp('conectado_em')->nullable();
            $table->timestamp('ultimo_evento_em')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_instances');
    }
};

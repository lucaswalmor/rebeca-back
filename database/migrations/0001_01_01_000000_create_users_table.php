<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_admin')->default(false);
            $table->string('nome');
            $table->string('sobrenome');
            $table->string('apelido');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('telefone');
            $table->date('data_nascimento');
            $table->string('instagram')->nullable();
            $table->string('telegram')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('x_twitter')->nullable();
            $table->string('tiktok')->nullable();
            $table->string('facebook')->nullable();
            $table->text('privacy')->nullable();
            $table->text('sobre')->nullable();
            $table->string('path_img_banner')->nullable();
            $table->string('path_img_avatar')->nullable();
            $table->decimal('valor_assinatura_mensal', 10, 2)->nullable();
            $table->decimal('valor_assinatura_trimestral', 10, 2)->nullable();
            $table->decimal('valor_assinatura_semestral', 10, 2)->nullable();
            $table->decimal('valor_desconto_trimestral', 10, 2)->nullable();
            $table->decimal('valor_desconto_semestral', 10, 2)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};

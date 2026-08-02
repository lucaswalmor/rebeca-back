<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('creditos', 12, 2)->default(0)->after('chat_audio_credits');
        });

        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('valor', 12, 2);
            $table->string('status')->default('pendente');
            $table->string('order_nsu')->nullable()->unique();
            $table->text('link_pagamento')->nullable();
            $table->string('transaction_nsu')->nullable();
            $table->string('invoice_slug')->nullable();
            $table->string('receipt_url')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->unsignedInteger('installments')->nullable();
            $table->string('capture_method')->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tipo'); // recarga | gasto | ajuste
            $table->decimal('valor', 12, 2);
            $table->decimal('saldo_apos', 12, 2);
            $table->string('referencia_tipo')->nullable(); // post_compra, chat_midia, chat_audio, recarga
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->string('descricao')->nullable();
            $table->string('order_nsu')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('credit_purchases');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('creditos');
        });
    }
};

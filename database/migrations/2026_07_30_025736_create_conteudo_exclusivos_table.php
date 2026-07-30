<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conteudo_exclusivos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 20); // image | video
            $table->decimal('valor', 10, 2);
            $table->string('status')->default('pendente');
            $table->string('order_nsu')->nullable()->unique();
            $table->text('link_pagamento')->nullable();
            $table->string('transaction_nsu')->nullable();
            $table->string('invoice_slug')->nullable();
            $table->text('receipt_url')->nullable();
            $table->decimal('paid_amount', 10, 2)->nullable();
            $table->unsignedInteger('installments')->nullable();
            $table->string('capture_method')->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
            $table->index(['subscriber_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conteudo_exclusivos');
    }
};

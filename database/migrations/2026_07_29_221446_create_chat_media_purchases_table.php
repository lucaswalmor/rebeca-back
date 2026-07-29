<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_media_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('valor', 10, 2);
            $table->unsignedInteger('credits')->default(5);
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_media_purchases');
    }
};

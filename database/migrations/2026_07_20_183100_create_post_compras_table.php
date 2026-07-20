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
        Schema::create('post_compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->string('order_nsu')->nullable()->unique();
            $table->string('status')->default('pendente'); // pendente, aprovado
            $table->decimal('valor', 10, 2);
            $table->text('link_pagamento')->nullable();
            $table->string('transaction_nsu')->nullable();
            $table->string('invoice_slug')->nullable();
            $table->string('receipt_url')->nullable();
            $table->decimal('paid_amount', 10, 2)->nullable();
            $table->integer('installments')->nullable();
            $table->string('capture_method')->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'post_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_compras');
    }
};

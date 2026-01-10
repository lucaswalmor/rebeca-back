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
        Schema::table('assinaturas', function (Blueprint $table) {
            $table->string('transaction_nsu')->nullable()->after('order_nsu');
            $table->string('invoice_slug')->nullable()->after('transaction_nsu');
            $table->text('receipt_url')->nullable()->after('invoice_slug');
            $table->decimal('paid_amount', 10, 2)->nullable()->after('valor');
            $table->integer('installments')->nullable()->after('paid_amount');
            $table->enum('capture_method', ['credit_card', 'pix', 'boleto'])->nullable()->after('installments');
            $table->timestamp('payment_date')->nullable()->after('capture_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assinaturas', function (Blueprint $table) {
            $table->dropColumn([
                'transaction_nsu',
                'invoice_slug',
                'receipt_url',
                'paid_amount',
                'installments',
                'capture_method',
                'payment_date'
            ]);
        });
    }
};

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditPurchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'valor',
        'status',
        'order_nsu',
        'link_pagamento',
        'transaction_nsu',
        'invoice_slug',
        'receipt_url',
        'paid_amount',
        'installments',
        'capture_method',
        'payment_date',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'payment_date' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

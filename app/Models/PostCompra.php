<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostCompra extends Model
{
    protected $table = 'post_compras';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'post_id',
        'order_nsu',
        'status',
        'valor',
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

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function isAprovado(): bool
    {
        return $this->status === 'aprovado';
    }
}

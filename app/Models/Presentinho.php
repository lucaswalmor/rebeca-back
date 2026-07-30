<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Presentinho extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'conversation_id',
        'admin_id',
        'subscriber_id',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subscriber_id');
    }

    public function isPago(): bool
    {
        return $this->status === 'aprovado';
    }

    public function toCardPayload(): array
    {
        $subscriber = $this->subscriber;

        return [
            'presentinho_id' => $this->id,
            'uuid' => $this->uuid,
            'valor' => (float) $this->valor,
            'status' => $this->status,
            'subscriber_name' => $subscriber?->apelido
                ?: trim(($subscriber?->nome ?? '').' '.($subscriber?->sobrenome ?? '')),
        ];
    }
}

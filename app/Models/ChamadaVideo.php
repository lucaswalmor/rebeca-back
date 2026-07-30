<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChamadaVideo extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'conversation_id',
        'admin_id',
        'subscriber_id',
        'titulo',
        'data',
        'horario',
        'duracao_minutos',
        'valor',
        'meet_link',
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
            'data' => 'date',
            'valor' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'payment_date' => 'datetime',
            'duracao_minutos' => 'integer',
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

    public function toCardPayload(string $cardKind = 'invoice'): array
    {
        $payload = [
            'chamada_video_id' => $this->id,
            'uuid' => $this->uuid,
            'titulo' => $this->titulo,
            'data' => $this->data?->format('Y-m-d'),
            'horario' => $this->horario,
            'duracao_minutos' => (int) $this->duracao_minutos,
            'valor' => (float) $this->valor,
            'status' => $this->status,
            'card_kind' => $cardKind,
        ];

        if ($cardKind === 'invoice') {
            $payload['payment_link'] = $this->link_pagamento;
            $payload['meet_link'] = null;
        } else {
            $payload['payment_link'] = null;
            $payload['meet_link'] = $this->meet_link;
        }

        return $payload;
    }
}

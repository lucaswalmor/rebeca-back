<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessagePurchase extends Model
{
    protected $fillable = [
        'message_id',
        'user_id',
        'valor',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

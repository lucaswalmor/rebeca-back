<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveParticipant extends Model
{
    protected $fillable = [
        'live_id',
        'user_id',
        'role',
        'chat_muted',
        'joined_at',
        'kicked_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_muted' => 'boolean',
            'joined_at' => 'datetime',
            'kicked_at' => 'datetime',
        ];
    }

    public function live(): BelongsTo
    {
        return $this->belongsTo(Live::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

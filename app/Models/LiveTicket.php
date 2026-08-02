<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveTicket extends Model
{
    protected $fillable = [
        'live_id',
        'user_id',
        'credits_paid',
    ];

    protected function casts(): array
    {
        return [
            'credits_paid' => 'integer',
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

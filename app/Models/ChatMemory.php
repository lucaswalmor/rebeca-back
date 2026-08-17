<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMemory extends Model
{
    protected $fillable = [
        'admin_id',
        'subscriber_id',
        'summary',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subscriber_id');
    }
}

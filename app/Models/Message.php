<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'type',
        'body',
        'media_path',
        'media_url',
        'reply_to_id',
        'edited_at',
        'delivered_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(MessageLike::class);
    }

    public function isMedia(): bool
    {
        return in_array($this->type, ['image', 'video'], true);
    }
}

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
        'is_locked',
        'price',
        'reply_to_id',
        'edited_at',
        'delivered_at',
        'read_at',
        'sent_by_ai',
    ];

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'price' => 'decimal:2',
            'edited_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'sent_by_ai' => 'boolean',
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

    public function purchases(): HasMany
    {
        return $this->hasMany(MessagePurchase::class);
    }

    public function isMedia(): bool
    {
        return in_array($this->type, ['image', 'video'], true);
    }

    public function isPaidContent(): bool
    {
        return (bool) $this->is_locked && round((float) $this->price, 2) > 0;
    }

    public function userHasAccess(?User $user): bool
    {
        if (! $this->isPaidContent()) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ($user->isAdmin() || (int) $user->id === (int) $this->user_id) {
            return true;
        }

        if ($this->relationLoaded('purchases')) {
            return $this->purchases->contains('user_id', $user->id);
        }

        return $this->purchases()->where('user_id', $user->id)->exists();
    }
}

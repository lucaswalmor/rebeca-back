<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_id',
        'subscriber_id',
        'last_message_at',
        'admin_last_read_at',
        'subscriber_last_read_at',
        'admin_cleared_at',
        'subscriber_cleared_at',
        'ai_enabled',
        'last_human_admin_at',
        'ai_pending_message_id',
        'ai_aggression_warned_at',
        'ai_blocked_at',
        'ai_blocked_reason',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'admin_last_read_at' => 'datetime',
            'subscriber_last_read_at' => 'datetime',
            'admin_cleared_at' => 'datetime',
            'subscriber_cleared_at' => 'datetime',
            'ai_enabled' => 'boolean',
            'last_human_admin_at' => 'datetime',
            'ai_aggression_warned_at' => 'datetime',
            'ai_blocked_at' => 'datetime',
        ];
    }

    public function isAiBlocked(): bool
    {
        return $this->ai_blocked_at !== null;
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subscriber_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function isParticipant(User $user): bool
    {
        return (int) $this->admin_id === (int) $user->id
            || (int) $this->subscriber_id === (int) $user->id;
    }

    public function otherParty(User $user): ?User
    {
        if ((int) $this->admin_id === (int) $user->id) {
            return $this->subscriber;
        }

        if ((int) $this->subscriber_id === (int) $user->id) {
            return $this->admin;
        }

        return null;
    }

    public function clearedAtFor(User $user): ?\Illuminate\Support\Carbon
    {
        if ((int) $this->admin_id === (int) $user->id) {
            return $this->admin_cleared_at;
        }

        if ((int) $this->subscriber_id === (int) $user->id) {
            return $this->subscriber_cleared_at;
        }

        return null;
    }

    public function unreadCountFor(User $user): int
    {
        $lastRead = (int) $this->admin_id === (int) $user->id
            ? $this->admin_last_read_at
            : $this->subscriber_last_read_at;

        $clearedAt = $this->clearedAtFor($user);

        return $this->messages()
            ->where('user_id', '!=', $user->id)
            ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
            ->when($clearedAt, fn ($q) => $q->where('created_at', '>', $clearedAt))
            ->count();
    }
}

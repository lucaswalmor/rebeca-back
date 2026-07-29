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
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'admin_last_read_at' => 'datetime',
            'subscriber_last_read_at' => 'datetime',
        ];
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

    public function unreadCountFor(User $user): int
    {
        $lastRead = (int) $this->admin_id === (int) $user->id
            ? $this->admin_last_read_at
            : $this->subscriber_last_read_at;

        return $this->messages()
            ->where('user_id', '!=', $user->id)
            ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
            ->count();
    }
}

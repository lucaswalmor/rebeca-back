<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Live extends Model
{
    use HasFactory;

    public const STATUS_AGENDADA = 'agendada';

    public const STATUS_AO_VIVO = 'ao_vivo';

    public const STATUS_ENCERRADA = 'encerrada';

    protected $fillable = [
        'uuid',
        'admin_id',
        'titulo',
        'descricao',
        'starts_at',
        'is_private',
        'price_credits',
        'max_participants',
        'status',
        'chat_enabled',
        'livekit_room',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'is_private' => 'boolean',
            'chat_enabled' => 'boolean',
            'price_credits' => 'integer',
            'max_participants' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Live $live) {
            if (! $live->uuid) {
                $live->uuid = (string) Str::uuid();
            }
            if (! $live->livekit_room) {
                $live->livekit_room = 'live-'.$live->uuid;
            }
        });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(LiveInvite::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(LiveParticipant::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(LiveTicket::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(LiveGoal::class)->orderBy('sort_order')->orderBy('id');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(LiveDonation::class);
    }

    public function isAgendada(): bool
    {
        return $this->status === self::STATUS_AGENDADA;
    }

    public function isAoVivo(): bool
    {
        return $this->status === self::STATUS_AO_VIVO;
    }

    public function isEncerrada(): bool
    {
        return $this->status === self::STATUS_ENCERRADA;
    }

    public function isGratis(): bool
    {
        return (int) $this->price_credits <= 0;
    }

    public function roomUrl(): string
    {
        return rtrim((string) config('services.infinitepay.frontend_url'), '/').'/lives/'.$this->uuid;
    }

    public function toApiArray(?User $viewer = null): array
    {
        $hasTicket = false;
        $isInvited = ! $this->is_private;
        $participant = null;
        $forAdmin = $viewer?->isAdmin() === true;

        if ($viewer) {
            $hasTicket = $this->tickets()->where('user_id', $viewer->id)->exists();
            if ($this->is_private) {
                $isInvited = $viewer->isAdmin()
                    || $this->invites()->where('user_id', $viewer->id)->exists();
            }
            $participant = $this->participants()->where('user_id', $viewer->id)->first();
        }

        $goals = $this->relationLoaded('goals')
            ? $this->goals
            : $this->goals()->get();

        $goalsPayload = $goals
            ->map(fn (LiveGoal $g) => $g->toApiArray($forAdmin))
            ->when(! $forAdmin, fn ($c) => $c->filter(fn (array $g) => $g['visible_to_viewers']))
            ->values()
            ->all();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'titulo' => $this->titulo,
            'descricao' => $this->descricao,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'is_private' => $this->is_private,
            'price_credits' => (int) $this->price_credits,
            'max_participants' => (int) $this->max_participants,
            'status' => $this->status,
            'chat_enabled' => $this->chat_enabled,
            'livekit_room' => $this->livekit_room,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'room_url' => $this->roomUrl(),
            'donation_chips' => LiveDonation::CHIPS,
            'participants_count' => $this->participants()
                ->whereNull('kicked_at')
                ->where('role', 'viewer')
                ->count(),
            'invite_ids' => $this->relationLoaded('invites')
                ? $this->invites->pluck('user_id')->values()->all()
                : $this->invites()->pluck('user_id')->values()->all(),
            'goals' => $goalsPayload,
            'viewer' => $viewer ? [
                'has_ticket' => $hasTicket || $this->isGratis() || $viewer->isAdmin(),
                'is_invited' => $isInvited || $viewer->isAdmin(),
                'chat_muted' => (bool) ($participant?->chat_muted),
                'is_kicked' => (bool) $participant?->kicked_at,
                'is_moderator' => (bool) ($participant?->is_moderator),
                'role' => $viewer->isAdmin() ? 'host' : ((bool) ($participant?->is_moderator) ? 'moderator' : 'viewer'),
            ] : null,
        ];
    }
}

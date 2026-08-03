<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveGoal extends Model
{
    public const MAX_PER_LIVE = 20;

    protected $fillable = [
        'live_id',
        'titulo',
        'target_credits',
        'current_credits',
        'hidden_by_admin',
        'completed_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'target_credits' => 'integer',
            'current_credits' => 'integer',
            'hidden_by_admin' => 'boolean',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function live(): BelongsTo
    {
        return $this->belongsTo(Live::class);
    }

    public function donations(): HasMany
    {
        return $this->hasMany(LiveDonation::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null
            || $this->current_credits >= $this->target_credits;
    }

    public function progressPercent(): int
    {
        if ($this->target_credits <= 0) {
            return 100;
        }

        return (int) min(100, round(($this->current_credits / $this->target_credits) * 100));
    }

    /** Visível para clientes: não oculta pelo admin e não completou 100%. */
    public function isVisibleToViewers(): bool
    {
        return ! $this->hidden_by_admin && ! $this->isCompleted();
    }

    public function toApiArray(bool $forAdmin = false): array
    {
        $percent = $this->progressPercent();
        $completed = $this->isCompleted();

        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'target_credits' => (int) $this->target_credits,
            'current_credits' => (int) $this->current_credits,
            'progress_percent' => $percent,
            'hidden_by_admin' => (bool) $this->hidden_by_admin,
            'completed' => $completed,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'sort_order' => (int) $this->sort_order,
            'visible_to_viewers' => $this->isVisibleToViewers(),
            'remaining_credits' => max(0, (int) $this->target_credits - (int) $this->current_credits),
        ];
    }
}

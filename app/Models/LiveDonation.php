<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveDonation extends Model
{
    public const CHIPS = [50, 100, 150, 200];

    protected $fillable = [
        'live_id',
        'user_id',
        'live_goal_id',
        'credits',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
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

    public function goal(): BelongsTo
    {
        return $this->belongsTo(LiveGoal::class, 'live_goal_id');
    }
}

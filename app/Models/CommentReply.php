<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentReply extends Model
{
    protected $fillable = [
        'comment_id',
        'user_id',
        'reply',
    ];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getTimeAgoAttribute(): string
    {
        return $this->formatTimeAgo($this->created_at);
    }

    private function formatTimeAgo($date): string
    {
        $carbon = Carbon::parse($date);
        $now = Carbon::now();
        $diff = $carbon->diffInMinutes($now);

        if ($diff < 1) {
            return 'Agora';
        }

        if ($diff < 60) {
            return $diff.' Min';
        }

        $diffHours = $carbon->diffInHours($now);
        if ($diffHours < 24) {
            return $diffHours.' H';
        }

        $diffDays = $carbon->diffInDays($now);
        if ($diffDays < 30) {
            return $diffDays.' Dia'.($diffDays > 1 ? 's' : '');
        }

        $diffMonths = $carbon->diffInMonths($now);
        if ($diffMonths < 12) {
            return $diffMonths.' Mês'.($diffMonths > 1 ? 'es' : '');
        }

        $diffYears = $carbon->diffInYears($now);

        return $diffYears.' Ano'.($diffYears > 1 ? 's' : '');
    }
}

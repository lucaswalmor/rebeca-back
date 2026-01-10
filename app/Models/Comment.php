<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Comment extends Model
{
    protected $fillable = [
        'post_id',
        'user_id',
        'comment',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reply(): HasOne
    {
        return $this->hasOne(CommentReply::class);
    }

    public function getTimeAgoAttribute(): string
    {
        return $this->formatTimeAgo($this->created_at);
    }

    private function formatTimeAgo($date): string
    {
        $carbon = Carbon::parse($date);
        $now = Carbon::now();
        $diff = (int) $carbon->diffInMinutes($now); // Converte para inteiro
    
        if ($diff < 1) {
            return 'Agora';
        }
    
        if ($diff < 60) {
            return $diff.' Min';
        }
    
        $diffHours = (int) $carbon->diffInHours($now);
        if ($diffHours < 24) {
            return $diffHours.' H';
        }
    
        $diffDays = (int) $carbon->diffInDays($now);
        if ($diffDays < 30) {
            return $diffDays.' Dia'.($diffDays > 1 ? 's' : '');
        }
    
        $diffMonths = (int) $carbon->diffInMonths($now);
        if ($diffMonths < 12) {
            return $diffMonths.' Mês'.($diffMonths > 1 ? 'es' : '');
        }
    
        $diffYears = (int) $carbon->diffInYears($now);
    
        return $diffYears.' Ano'.($diffYears > 1 ? 's' : '');
    }
}

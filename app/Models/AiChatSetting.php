<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiChatSetting extends Model
{
    protected $fillable = [
        'admin_id',
        'enabled',
        'scope',
        'system_prompt',
        'reply_delay_minutes',
        'takeover_minutes',
        'quiet_hours_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'reply_delay_minutes' => 'integer',
            'takeover_minutes' => 'integer',
            'quiet_hours_enabled' => 'boolean',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function prompt(): string
    {
        $custom = trim((string) $this->system_prompt);

        return $custom !== '' ? $custom : (string) config('xai.default_prompt');
    }

    public function isSelectedScope(): bool
    {
        return $this->scope !== 'all';
    }
}

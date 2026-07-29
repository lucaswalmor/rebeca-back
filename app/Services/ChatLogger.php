<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class ChatLogger
{
    public static function info(string $message, array $context = []): void
    {
        if (! config('chat.debug')) {
            return;
        }

        Log::channel('single')->info('[CHAT] '.$message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        Log::channel('single')->error('[CHAT] '.$message, $context);
    }

    public static function isOnline(?User $user): bool
    {
        if (! $user || ! $user->last_seen_at) {
            return false;
        }

        return $user->last_seen_at->gt(
            now()->subSeconds((int) config('chat.online_threshold_seconds', 120))
        );
    }

    public static function adminUser(): ?User
    {
        return User::query()->where('is_admin', true)->orderBy('id')->first();
    }
}

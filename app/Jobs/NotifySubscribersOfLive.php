<?php

namespace App\Jobs;

use App\Mail\LiveScheduledMail;
use App\Models\Live;
use App\Models\User;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifySubscribersOfLive
{
    use Queueable;

    public function __construct(
        public int $liveId,
    ) {}

    public function handle(): void
    {
        $live = Live::query()->with('invites')->find($this->liveId);

        if (! $live || $live->isEncerrada()) {
            return;
        }

        $hoje = now()->startOfDay();

        $query = User::query()
            ->where('is_admin', false)
            ->where('is_blocked', false)
            ->where('notify_live_email', true)
            ->whereNotNull('email');

        if ($live->is_private) {
            $inviteIds = $live->invites->pluck('user_id')->all();
            if ($inviteIds === []) {
                return;
            }
            $query->whereIn('id', $inviteIds);
        } else {
            $query->whereHas('assinaturas', function ($q) use ($hoje) {
                $q->where('status', 'aprovado')
                    ->where('data_inicio', '<=', $hoje)
                    ->where('data_fim', '>=', $hoje);
            });
        }

        $query->orderBy('id')
            ->chunkById(100, function ($users) use ($live) {
                foreach ($users as $user) {
                    try {
                        Mail::to($user->email)->send(new LiveScheduledMail($user, $live));
                    } catch (\Throwable $e) {
                        Log::warning('Falha ao enviar e-mail de live', [
                            'user_id' => $user->id,
                            'live_id' => $live->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}

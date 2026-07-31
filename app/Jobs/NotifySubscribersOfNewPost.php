<?php

namespace App\Jobs;

use App\Mail\NewPostPublishedMail;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifySubscribersOfNewPost
{
    use Queueable;

    public function __construct(
        public int $postId,
    ) {}

    public function handle(): void
    {
        $post = Post::query()->find($this->postId);

        if (! $post || $post->status !== 'ativo') {
            return;
        }

        $hoje = now()->startOfDay();

        User::query()
            ->where('is_admin', false)
            ->where('is_blocked', false)
            ->where('notify_new_posts_email', true)
            ->whereNotNull('email')
            ->whereHas('assinaturas', function ($query) use ($hoje) {
                $query->where('status', 'aprovado')
                    ->where('data_inicio', '<=', $hoje)
                    ->where('data_fim', '>=', $hoje);
            })
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($post) {
                foreach ($users as $user) {
                    try {
                        Mail::to($user->email)->send(new NewPostPublishedMail($user, $post));
                    } catch (\Throwable $e) {
                        Log::warning('Falha ao enviar e-mail de novo post', [
                            'user_id' => $user->id,
                            'post_id' => $post->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}

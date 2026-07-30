<?php

namespace App\Services;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Assinatura;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WelcomeMessageService
{
    /**
     * Envia a mensagem inicial configurada pela admin na 1ª assinatura aprovada.
     */
    public function sendOnFirstSubscription(User $subscriber): void
    {
        if ($subscriber->isAdmin()) {
            return;
        }

        if ($subscriber->chat_welcome_sent_at) {
            return;
        }

        $approvedCount = Assinatura::query()
            ->where('user_id', $subscriber->id)
            ->where('status', 'aprovado')
            ->count();

        if ($approvedCount !== 1) {
            return;
        }

        $admin = ChatLogger::adminUser();
        if (! $admin) {
            return;
        }

        if (! $this->adminHasTemplate($admin)) {
            return;
        }

        $claimed = User::query()
            ->where('id', $subscriber->id)
            ->whereNull('chat_welcome_sent_at')
            ->update(['chat_welcome_sent_at' => now()]);

        if (! $claimed) {
            return;
        }

        try {
            $this->deliver($admin, $subscriber);
        } catch (\Throwable $e) {
            User::query()
                ->where('id', $subscriber->id)
                ->update(['chat_welcome_sent_at' => null]);

            Log::error('[CHAT] Falha ao enviar mensagem inicial', [
                'subscriber_id' => $subscriber->id,
                'erro' => $e->getMessage(),
            ]);
        }
    }

    public function adminHasTemplate(User $admin): bool
    {
        return trim((string) $admin->welcome_titulo) !== ''
            || trim((string) $admin->welcome_body) !== ''
            || filled($admin->welcome_image_url)
            || filled($admin->welcome_video_url)
            || filled($admin->welcome_audio_url);
    }

    private function deliver(User $admin, User $subscriber): void
    {
        $conversation = Conversation::query()->firstOrCreate(
            [
                'admin_id' => $admin->id,
                'subscriber_id' => $subscriber->id,
            ]
        );

        $latestPreview = null;
        $titulo = trim((string) $admin->welcome_titulo);
        $body = trim((string) $admin->welcome_body);

        $textBody = null;
        if ($titulo !== '' && $body !== '') {
            $textBody = $titulo."\n\n".$body;
        } elseif ($titulo !== '') {
            $textBody = $titulo;
        } elseif ($body !== '') {
            $textBody = $body;
        }

        if ($textBody !== null) {
            $latestPreview = $this->createAndBroadcastText($admin, $conversation, $textBody);
        }

        if (filled($admin->welcome_image_url)) {
            $latestPreview = $this->createAndBroadcastMedia(
                $admin,
                $conversation,
                'image',
                (string) $admin->welcome_image_url,
                'Foto'
            ) ?: $latestPreview;
        }

        if (filled($admin->welcome_video_url)) {
            $latestPreview = $this->createAndBroadcastMedia(
                $admin,
                $conversation,
                'video',
                (string) $admin->welcome_video_url,
                'Vídeo'
            ) ?: $latestPreview;
        }

        if (filled($admin->welcome_audio_url)) {
            $duration = max(1, (int) ($admin->welcome_audio_duration ?: 1));
            $latestPreview = $this->createAndBroadcastMedia(
                $admin,
                $conversation,
                'audio',
                (string) $admin->welcome_audio_url,
                'Áudio',
                (string) $duration
            ) ?: $latestPreview;
        }

        if ($latestPreview !== null) {
            broadcast(new ConversationUpdated(
                $conversation->id,
                $conversation->admin_id,
                $conversation->subscriber_id,
                [
                    'unread_bump' => true,
                    'sender' => [
                        'id' => $admin->id,
                        'nome' => $admin->nome,
                        'apelido' => $admin->apelido,
                    ],
                    'latest_message' => $latestPreview,
                ]
            ));
        }

        ChatLogger::info('Welcome message sent', [
            'subscriber_id' => $subscriber->id,
            'conversation_id' => $conversation->id,
        ]);
    }

    private function createAndBroadcastText(User $admin, Conversation $conversation, string $textBody): array
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $admin->id,
            'type' => 'text',
            'body' => $textBody,
            'delivered_at' => now(),
        ]);
        $conversation->update(['last_message_at' => now()]);
        $message->load(['user', 'replyTo.user', 'likes']);
        broadcast(new MessageSent($message))->toOthers();

        return [
            'id' => $message->id,
            'type' => $message->type,
            'body' => $message->body,
            'user_id' => $message->user_id,
            'conversation_id' => $conversation->id,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function createAndBroadcastMedia(
        User $admin,
        Conversation $conversation,
        string $type,
        string $sourceUrl,
        string $previewLabel,
        ?string $body = null
    ): ?array {
        $sourcePath = $this->pathFromStoredUrl($sourceUrl);
        if (! $sourcePath) {
            return null;
        }

        try {
            $binary = Storage::disk('s3')->get($sourcePath);
        } catch (\Throwable $e) {
            Log::error('[CHAT] Não foi possível ler mídia da mensagem inicial', [
                'path' => $sourcePath,
                'erro' => $e->getMessage(),
            ]);

            return null;
        }

        $ext = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: match ($type) {
            'image' => 'jpg',
            'video' => 'mp4',
            default => 'webm',
        };

        $mediaPath = 'chat/'.$conversation->id.'/'.time().'_'.uniqid().'.'.$ext;
        Storage::disk('s3')->put($mediaPath, $binary, 'public');
        $mediaUrl = rtrim((string) env('AWS_URL'), '/').'/'.$mediaPath;

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $admin->id,
            'type' => $type,
            'body' => $body,
            'media_path' => $mediaPath,
            'media_url' => $mediaUrl,
            'delivered_at' => now(),
        ]);
        $conversation->update(['last_message_at' => now()]);
        $message->load(['user', 'replyTo.user', 'likes']);
        broadcast(new MessageSent($message))->toOthers();

        return [
            'id' => $message->id,
            'type' => $message->type,
            'body' => $previewLabel,
            'user_id' => $message->user_id,
            'conversation_id' => $conversation->id,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function pathFromStoredUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = ltrim((string) $path, '/');
        $bucket = (string) config('filesystems.disks.s3.bucket');

        if ($bucket !== '' && str_starts_with($path, $bucket.'/')) {
            $path = substr($path, strlen($bucket) + 1);
        }

        if (str_starts_with($path, 'rebeca/')) {
            $path = substr($path, 7);
        }

        return $path !== '' ? $path : null;
    }
}

<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class ChatExportService
{
    /**
     * @return array{
     *     exported_at: string,
     *     conversations_count: int,
     *     messages_count: int,
     *     conversations: list<array<string, mixed>>
     * }
     */
    public function forAdmin(User $admin): array
    {
        $conversations = Conversation::query()
            ->where('admin_id', $admin->id)
            ->whereHas('messages')
            ->with([
                'subscriber:id,nome,sobrenome,apelido',
                'messages' => fn ($q) => $q->orderBy('created_at')->orderBy('id'),
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $exported = [];
        $messagesCount = 0;

        foreach ($conversations as $conversation) {
            $turns = $conversation->messages->map(function (Message $message) use ($admin) {
                $fromAdmin = (int) $message->user_id === (int) $admin->id;

                return [
                    'from' => $fromAdmin ? 'admin' : 'cliente',
                    'via_ia' => $fromAdmin ? (bool) $message->sent_by_ai : false,
                    'type' => $message->type,
                    'text' => $this->messageText($message),
                    'created_at' => $message->created_at?->toIso8601String(),
                ];
            })->values()->all();

            $messagesCount += count($turns);
            $subscriber = $conversation->subscriber;

            $exported[] = [
                'id' => $conversation->id,
                'cliente' => $subscriber ? [
                    'id' => $subscriber->id,
                    'nome' => trim(($subscriber->nome ?? '').' '.($subscriber->sobrenome ?? '')) ?: null,
                    'apelido' => $subscriber->apelido,
                ] : null,
                'messages' => $turns,
            ];
        }

        return [
            'exported_at' => now()->toIso8601String(),
            'conversations_count' => count($exported),
            'messages_count' => $messagesCount,
            'conversations' => $exported,
        ];
    }

    private function messageText(Message $message): string
    {
        $label = match ($message->type) {
            'image' => '[foto]',
            'video' => '[vídeo]',
            'audio' => '[áudio]',
            'pix_key' => '[chave pix]',
            'presentinho', 'presentinho_offer' => '[presentinho]',
            'video_call' => '[chamada de vídeo]',
            'conteudo_exclusivo' => '[conteúdo exclusivo]',
            default => trim((string) $message->body),
        };

        if ($label !== '') {
            return $label;
        }

        return '['.$message->type.']';
    }
}

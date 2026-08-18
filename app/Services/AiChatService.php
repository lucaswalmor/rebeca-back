<?php

namespace App\Services;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Jobs\GenerateAiChatReply;
use App\Models\AiChatSetting;
use App\Models\ChatMemory;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AiChatService
{
    public function settingsFor(User $admin): AiChatSetting
    {
        return AiChatSetting::query()->firstOrNew(
            ['admin_id' => $admin->id],
            [
                'enabled' => false,
                'scope' => 'selected',
                'system_prompt' => null,
                'reply_delay_minutes' => (int) config('xai.default_reply_delay_minutes', 5),
                'takeover_minutes' => (int) config('xai.default_takeover_minutes', 15),
                'quiet_hours_enabled' => (bool) config('xai.default_quiet_hours_enabled', true),
                'quiet_hours_start' => (string) config('xai.default_quiet_hours_start', '02:00'),
                'quiet_hours_end' => (string) config('xai.default_quiet_hours_end', '11:00'),
            ]
        );
    }

    public function onMessageCreated(Message $message, Conversation $conversation, User $sender): void
    {
        if ($sender->isAdmin()) {
            if (! $message->sent_by_ai) {
                $conversation->forceFill([
                    'last_human_admin_at' => now(),
                    'ai_pending_message_id' => null,
                ])->save();
            }

            return;
        }

        $this->scheduleReply($conversation, $message);
    }

    public function scheduleReply(Conversation $conversation, Message $message): void
    {
        $conversation->loadMissing(['admin', 'subscriber']);
        $admin = $conversation->admin;

        if (! $admin) {
            return;
        }

        $settings = $this->settingsFor($admin);

        if (! $this->isEligible($conversation, $settings, $admin)) {
            return;
        }

        $conversation->forceFill(['ai_pending_message_id' => $message->id])->save();

        $readyAt = $this->replyReadyAt($conversation, $settings, $message);

        GenerateAiChatReply::dispatch($conversation->id, $message->id)
            ->delay($readyAt->isFuture() ? $readyAt : now());
    }

    public function isEligible(Conversation $conversation, AiChatSetting $settings, User $admin): bool
    {
        if (! $settings->enabled) {
            return false;
        }

        if ((string) config('xai.api_key') === '') {
            return false;
        }

        if ($conversation->subscriber?->chat_blocked) {
            return false;
        }

        if ($conversation->isAiBlocked()) {
            return false;
        }

        if ($settings->isSelectedScope() && ! $conversation->ai_enabled) {
            return false;
        }

        return (int) $conversation->admin_id === (int) $admin->id;
    }

    public function replyReadyAt(Conversation $conversation, AiChatSetting $settings, Message $trigger): Carbon
    {
        $readyAt = $trigger->created_at?->copy() ?? now();
        $readyAt->addMinutes(max(0, (int) $settings->reply_delay_minutes));

        if ($conversation->last_human_admin_at) {
            $takeoverAt = $conversation->last_human_admin_at
                ->copy()
                ->addMinutes(max(0, (int) $settings->takeover_minutes));

            if ($takeoverAt->gt($readyAt)) {
                $readyAt = $takeoverAt;
            }
        }

        return $this->deferForQuietHours($settings, $readyAt);
    }

    public function deferForQuietHours(AiChatSetting $settings, Carbon $readyAt): Carbon
    {
        $until = $this->quietHoursUntil($settings, $readyAt);

        if ($until && $until->gt($readyAt)) {
            return $until;
        }

        return $readyAt;
    }

    public function quietHoursUntil(AiChatSetting $settings, Carbon $at): ?Carbon
    {
        if (! $settings->quiet_hours_enabled) {
            return null;
        }

        $start = $this->minutesFromHhmm((string) $settings->quiet_hours_start);
        $end = $this->minutesFromHhmm((string) $settings->quiet_hours_end);

        if ($start === null || $end === null || $start === $end) {
            return null;
        }

        $tz = (string) config('xai.quiet_hours_timezone', 'America/Sao_Paulo');
        $local = $at->copy()->timezone($tz);
        $localMinutes = ($local->hour * 60) + $local->minute;

        $inside = $start < $end
            ? ($localMinutes >= $start && $localMinutes < $end)
            : ($localMinutes >= $start || $localMinutes < $end);

        if (! $inside) {
            return null;
        }

        $endAt = $local->copy()->setTime(intdiv($end, 60), $end % 60, 0);

        if ($start > $end && $localMinutes >= $start) {
            $endAt->addDay();
        }

        return $endAt->timezone((string) config('app.timezone'));
    }

    private function minutesFromHhmm(string $value): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})/', $value, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return ($hour * 60) + $minute;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function buildMessages(Conversation $conversation, AiChatSetting $settings): array
    {
        $limit = (int) config('xai.history_limit', 40);
        $adminId = (int) $conversation->admin_id;
        $subscriber = $conversation->subscriber;

        $memory = ChatMemory::query()
            ->where('admin_id', $adminId)
            ->where('subscriber_id', $conversation->subscriber_id)
            ->value('summary');

        $history = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $system = $settings->prompt();
        $system .= "\n\nAssinante: ".trim(($subscriber?->nome ?? '').' '.($subscriber?->apelido ? '('.$subscriber->apelido.')' : ''));

        if (filled($memory)) {
            $system .= "\n\nMemória deste cliente:\n".$memory;
        }

        $warned = $conversation->ai_aggression_warned_at !== null;
        $system .= "\n\nEstado interno desta conversa (nunca mostre nem envie ao assinante):";
        $system .= "\nAGRESSAO_ADVERTIDA = ".($warned ? 'true' : 'false');
        $system .= "\nNa primeira agressão clara, avise o assinante e acrescente [AGRESSAO_ADVERTIDA] no final da resposta.";
        $system .= "\nSe AGRESSAO_ADVERTIDA já for true e houver nova agressão, responda SOMENTE com [ENCERRAR_CONVERSA].";

        $messages = [
            ['role' => 'system', 'content' => $system],
        ];

        foreach ($history as $item) {
            $role = (int) $item->user_id === $adminId ? 'assistant' : 'user';
            $content = $this->messageToPrompt($item);

            if ($content === '') {
                continue;
            }

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $messages;
    }

    /**
     * @return array{end_conversation: bool, aggression_warned: bool, text: string}
     */
    public function parseSafetyReply(string $reply): array
    {
        $end = (bool) preg_match('/\[ENCERRAR_CONVERSA\]/iu', $reply);
        $warned = (bool) preg_match('/\[AGRESSAO_ADVERTIDA\]|AGRESSAO_ADVERTIDA\s*=\s*true/iu', $reply);

        $text = preg_replace('/\[ENCERRAR_CONVERSA\]/iu', '', $reply) ?? $reply;
        $text = preg_replace('/\[AGRESSAO_ADVERTIDA\]/iu', '', $text) ?? $text;
        $text = preg_replace('/AGRESSAO_ADVERTIDA\s*=\s*(true|false)/iu', '', $text) ?? $text;
        $text = trim(preg_replace("/[ \t]+\n/", "\n", preg_replace("/\n{3,}/", "\n\n", $text) ?? $text) ?? $text);

        return [
            'end_conversation' => $end,
            'aggression_warned' => $warned,
            'text' => $text,
        ];
    }

    public function blockForAggression(Conversation $conversation): void
    {
        $conversation->forceFill([
            'ai_enabled' => false,
            'ai_blocked_at' => now(),
            'ai_blocked_reason' => 'agressividade_recorrente',
            'ai_pending_message_id' => null,
        ])->save();

        Log::info('[AI-CHAT] Conversa bloqueada por agressividade recorrente', [
            'conversation_id' => $conversation->id,
        ]);
    }

    public function markAggressionWarned(Conversation $conversation): void
    {
        if ($conversation->ai_aggression_warned_at) {
            return;
        }

        $conversation->forceFill([
            'ai_aggression_warned_at' => now(),
        ])->save();
    }

    public function setConversationAiEnabled(Conversation $conversation, bool $enabled): void
    {
        $payload = [
            'ai_enabled' => $enabled,
            'ai_pending_message_id' => null,
        ];

        if ($enabled) {
            $payload['ai_blocked_at'] = null;
            $payload['ai_blocked_reason'] = null;
            $payload['ai_aggression_warned_at'] = null;
        }

        $conversation->forceFill($payload)->save();
    }

    public function publishReply(Conversation $conversation, User $admin, string $body): Message
    {
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $admin->id,
            'type' => 'text',
            'body' => $body,
            'sent_by_ai' => true,
            'delivered_at' => now(),
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
            'ai_pending_message_id' => null,
        ])->save();

        $message->load(['user', 'replyTo.user', 'likes']);

        broadcast(new MessageSent($message))->toOthers();
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
                'latest_message' => [
                    'id' => $message->id,
                    'type' => $message->type,
                    'body' => $message->body,
                    'user_id' => $message->user_id,
                    'conversation_id' => $conversation->id,
                    'created_at' => $message->created_at?->toIso8601String(),
                ],
            ]
        ));

        return $message;
    }

    public function looksLikeRefusal(string $text): bool
    {
        $normalized = mb_strtolower($text);

        $needles = [
            'sou uma ia',
            'sou um modelo',
            'como inteligência artificial',
            'i cannot',
            'i can\'t assist',
            'against my',
            'content policy',
            'não posso ajudar com isso',
            'não posso gerar esse tipo',
            'não posso continuar essa conversa',
        ];

        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function splitReply(string $text): array
    {
        $parts = preg_split("/\n{2,}/", trim($text)) ?: [];
        $clean = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $clean[] = mb_substr($part, 0, 5000);
            if (count($clean) >= 3) {
                break;
            }
        }

        return $clean !== [] ? $clean : [mb_substr(trim($text), 0, 5000)];
    }

    public function maybeRefreshMemory(Conversation $conversation, User $admin, GrokChatClient $client): void
    {
        $aiCount = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sent_by_ai', true)
            ->count();

        if ($aiCount === 0 || $aiCount % 8 !== 0) {
            return;
        }

        $history = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->reverse()
            ->map(fn (Message $item) => $this->messageToPrompt($item, (int) $admin->id === (int) $item->user_id ? 'Beca' : 'Cliente'))
            ->filter()
            ->implode("\n");

        $summary = $client->complete([
            [
                'role' => 'system',
                'content' => 'Resuma em até 800 caracteres o que a Beca deve lembrar deste assinante (nome, apelidos, gostos, fetiches, combinados). Sem aspas, só o texto corrido.',
            ],
            [
                'role' => 'user',
                'content' => $history,
            ],
        ]);

        if (! $summary || $this->looksLikeRefusal($summary)) {
            return;
        }

        ChatMemory::query()->updateOrCreate(
            [
                'admin_id' => $admin->id,
                'subscriber_id' => $conversation->subscriber_id,
            ],
            [
                'summary' => mb_substr($summary, 0, 2000),
            ]
        );
    }

    private function messageToPrompt(Message $message, ?string $who = null): string
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

        if ($who) {
            return $who.': '.$label;
        }

        return $label;
    }
}

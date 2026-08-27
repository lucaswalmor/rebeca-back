<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AiChatService;
use App\Services\GrokChatClient;
use App\Services\HumanHandoffNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateAiChatReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 120;

    public function __construct(
        public int $conversationId,
        public int $messageId,
    ) {}

    public function handle(AiChatService $aiChat, GrokChatClient $grok, HumanHandoffNotifier $notifier): void
    {
        $lock = Cache::lock('ai-chat-'.$this->conversationId, 90);

        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $this->process($aiChat, $grok, $notifier);
        } finally {
            $lock->release();
        }
    }

    private function process(AiChatService $aiChat, GrokChatClient $grok, HumanHandoffNotifier $notifier): void
    {
        $conversation = Conversation::query()
            ->with(['admin', 'subscriber'])
            ->find($this->conversationId);

        $trigger = Message::query()->find($this->messageId);

        if (! $conversation || ! $trigger || ! $conversation->admin) {
            return;
        }

        $admin = $conversation->admin;
        $settings = $aiChat->settingsFor($admin);

        if (! $aiChat->isEligible($conversation, $settings, $admin)) {
            return;
        }

        if ((int) $conversation->ai_pending_message_id !== (int) $trigger->id) {
            return;
        }

        $latest = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->first();

        if (! $latest || (int) $latest->user_id === (int) $conversation->admin_id) {
            $conversation->forceFill(['ai_pending_message_id' => null])->save();

            return;
        }

        if ((int) $latest->id !== (int) $trigger->id) {
            return;
        }

        $readyAt = $aiChat->replyReadyAt($conversation, $settings, $trigger);

        if ($readyAt->isFuture()) {
            $this->release(max(5, $readyAt->getTimestamp() - now()->getTimestamp()));

            return;
        }

        $payload = $aiChat->buildMessages($conversation, $settings);
        $reply = $grok->complete($payload);

        if (! $reply) {
            Log::warning('[AI-CHAT] Resposta vazia ou recusada', [
                'conversation_id' => $conversation->id,
                'message_id' => $trigger->id,
            ]);

            return;
        }

        $parsed = $aiChat->parseSafetyReply($reply);

        if ($parsed['end_conversation']) {
            $aiChat->blockForAggression($conversation);

            return;
        }

        if ($parsed['human_handoff']) {
            $aiChat->pauseForHumanHandoff($conversation, $notifier);

            return;
        }

        if ($aiChat->looksLikeRefusal($parsed['text'])) {
            Log::warning('[AI-CHAT] Resposta vazia ou recusada', [
                'conversation_id' => $conversation->id,
                'message_id' => $trigger->id,
            ]);

            return;
        }

        if ($parsed['aggression_warned']) {
            $aiChat->markAggressionWarned($conversation);
        }

        if ($parsed['text'] === '') {
            $conversation->forceFill(['ai_pending_message_id' => null])->save();

            return;
        }

        $conversation->refresh();

        if ((int) $conversation->ai_pending_message_id !== (int) $trigger->id) {
            return;
        }

        $latestAfter = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->first();

        if (! $latestAfter || (int) $latestAfter->user_id === (int) $conversation->admin_id) {
            $conversation->forceFill(['ai_pending_message_id' => null])->save();

            return;
        }

        if (! $aiChat->isEligible($conversation->fresh(), $aiChat->settingsFor($admin->fresh()), $admin)) {
            return;
        }

        $chunks = $aiChat->splitReply($parsed['text']);
        $first = array_shift($chunks);

        if ($first === null || $first === '') {
            $conversation->forceFill(['ai_pending_message_id' => null])->save();

            return;
        }

        $published = $aiChat->publishReply($conversation, $admin, $first);

        if ($chunks === []) {
            $aiChat->maybeRefreshMemory($conversation, $admin, $grok);

            return;
        }

        PublishAiChatChunk::dispatch($conversation->id, $published->id, $chunks)
            ->delay($aiChat->nextChunkDelay());
    }
}

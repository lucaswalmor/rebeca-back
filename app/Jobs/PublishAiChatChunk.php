<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\AiChatService;
use App\Services\GrokChatClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class PublishAiChatChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 30;

    /**
     * @param  list<string>  $remainingChunks
     */
    public function __construct(
        public int $conversationId,
        public int $previousMessageId,
        public array $remainingChunks,
    ) {}

    public function handle(AiChatService $aiChat, GrokChatClient $grok): void
    {
        $lock = Cache::lock('ai-chat-'.$this->conversationId, 30);

        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            $this->process($aiChat, $grok);
        } finally {
            $lock->release();
        }
    }

    private function process(AiChatService $aiChat, GrokChatClient $grok): void
    {
        if ($this->remainingChunks === []) {
            return;
        }

        $conversation = Conversation::query()
            ->with(['admin', 'subscriber'])
            ->find($this->conversationId);

        if (! $conversation || ! $conversation->admin) {
            return;
        }

        $admin = $conversation->admin;
        $settings = $aiChat->settingsFor($admin);

        if (! $aiChat->isEligible($conversation, $settings, $admin)) {
            return;
        }

        if (! $aiChat->canPublishNextChunk($conversation, $this->previousMessageId)) {
            return;
        }

        $chunk = (string) array_shift($this->remainingChunks);
        $chunk = trim($chunk);

        if ($chunk === '') {
            if ($this->remainingChunks !== []) {
                self::dispatch($this->conversationId, $this->previousMessageId, $this->remainingChunks)
                    ->delay($aiChat->nextChunkDelay());
            }

            return;
        }

        $published = $aiChat->publishReply($conversation, $admin, $chunk);

        if ($this->remainingChunks === []) {
            $aiChat->maybeRefreshMemory($conversation, $admin, $grok);

            return;
        }

        self::dispatch($this->conversationId, $published->id, $this->remainingChunks)
            ->delay($aiChat->nextChunkDelay());
    }
}

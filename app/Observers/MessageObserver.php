<?php

namespace App\Observers;

use App\Models\Message;
use App\Services\AiChatService;

class MessageObserver
{
    public function __construct(private AiChatService $aiChat) {}

    public function created(Message $message): void
    {
        $message->loadMissing(['conversation.subscriber', 'conversation.admin', 'user']);

        $conversation = $message->conversation;
        $sender = $message->user;

        if (! $conversation || ! $sender) {
            return;
        }

        $this->aiChat->onMessageCreated($message, $conversation, $sender);
    }
}

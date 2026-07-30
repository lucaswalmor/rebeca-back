<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
        $this->message->load(['user', 'replyTo.user', 'likes']);
    }

    public function broadcastOn(): array
    {
        $this->message->loadMissing('conversation');

        $channels = [
            new PrivateChannel('chat.'.$this->message->conversation_id),
        ];

        $conversation = $this->message->conversation;
        if ($conversation) {
            $channels[] = new PrivateChannel('chat.inbox.'.$conversation->admin_id);
            $channels[] = new PrivateChannel('chat.inbox.'.$conversation->subscriber_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => (new MessageResource($this->message))->resolve(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $other = null;

        if ($user) {
            if ((int) $this->admin_id === (int) $user->id && $this->relationLoaded('subscriber')) {
                $other = $this->subscriber;
            } elseif ((int) $this->subscriber_id === (int) $user->id && $this->relationLoaded('admin')) {
                $other = $this->admin;
            }
        }

        $latest = $this->relationLoaded('latestMessage') ? $this->latestMessage : null;
        $threshold = (int) config('chat.online_threshold_seconds', 120);

        return [
            'id' => $this->id,
            'admin_id' => $this->admin_id,
            'subscriber_id' => $this->subscriber_id,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'unread_count' => $user ? $this->unreadCountFor($user) : 0,
            'other_user' => $other ? [
                'id' => $other->id,
                'nome' => $other->nome,
                'sobrenome' => $other->sobrenome,
                'apelido' => $other->apelido,
                'path_img_avatar' => $other->path_img_avatar,
                'is_admin' => $other->isAdmin(),
                'is_online' => $other->last_seen_at
                    && $other->last_seen_at->gt(now()->subSeconds($threshold)),
            ] : null,
            'latest_message' => $latest ? [
                'id' => $latest->id,
                'type' => $latest->type,
                'body' => $latest->body,
                'user_id' => $latest->user_id,
                'created_at' => $latest->created_at?->toIso8601String(),
                'read_at' => $latest->read_at?->toIso8601String(),
            ] : null,
        ];
    }
}

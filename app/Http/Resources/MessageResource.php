<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'user_id' => $this->user_id,
            'type' => $this->type,
            'body' => $this->body,
            'media_url' => $this->media_url,
            'reply_to_id' => $this->reply_to_id,
            'reply_to' => $this->whenLoaded('replyTo', function () {
                if (! $this->replyTo) {
                    return null;
                }

                return [
                    'id' => $this->replyTo->id,
                    'body' => $this->replyTo->body,
                    'type' => $this->replyTo->type,
                    'media_url' => $this->replyTo->media_url,
                    'user' => $this->replyTo->relationLoaded('user') && $this->replyTo->user
                        ? [
                            'id' => $this->replyTo->user->id,
                            'nome' => $this->replyTo->user->nome,
                            'apelido' => $this->replyTo->user->apelido,
                        ]
                        : null,
                ];
            }),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nome' => $this->user->nome,
                'apelido' => $this->user->apelido,
                'path_img_avatar' => $this->user->path_img_avatar,
                'is_admin' => $this->user->isAdmin(),
            ]),
            'likes_count' => $this->whenLoaded('likes', fn () => $this->likes->count(), 0),
            'liked_by_me' => $user && $this->relationLoaded('likes')
                ? $this->likes->contains('user_id', $user->id)
                : false,
            'likes' => $this->whenLoaded('likes', fn () => $this->likes->pluck('user_id')),
        ];
    }
}

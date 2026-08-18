<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAiChatSettingsRequest;
use App\Models\AiChatSetting;
use App\Models\Conversation;
use App\Services\AiChatService;
use App\Services\GrokBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiChatController extends Controller
{
    public function __construct(
        private AiChatService $aiChat,
        private GrokBillingService $billing,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode ver essas configurações.'], 403);
        }

        $settings = $this->aiChat->settingsFor($admin);
        $conversations = Conversation::query()
            ->where('admin_id', $admin->id)
            ->with(['subscriber'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'subscriber_id' => $conversation->subscriber_id,
                'ai_enabled' => (bool) $conversation->ai_enabled,
                'ai_blocked' => $conversation->isAiBlocked(),
                'ai_blocked_reason' => $conversation->ai_blocked_reason,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'subscriber' => $conversation->subscriber ? [
                    'id' => $conversation->subscriber->id,
                    'nome' => $conversation->subscriber->nome,
                    'sobrenome' => $conversation->subscriber->sobrenome,
                    'apelido' => $conversation->subscriber->apelido,
                    'path_img_avatar' => $conversation->subscriber->path_img_avatar,
                ] : null,
            ])
            ->values();

        return response()->json([
            'data' => [
                'enabled' => (bool) $settings->enabled,
                'scope' => $settings->scope ?: 'selected',
                'system_prompt' => $settings->system_prompt,
                'default_prompt' => (string) config('xai.default_prompt'),
                'reply_delay_minutes' => (int) ($settings->reply_delay_minutes ?: config('xai.default_reply_delay_minutes', 5)),
                'takeover_minutes' => (int) ($settings->takeover_minutes ?: config('xai.default_takeover_minutes', 15)),
                'quiet_hours_enabled' => (bool) ($settings->quiet_hours_enabled ?? true),
                'quiet_hours_start' => $settings->quiet_hours_start ?: (string) config('xai.default_quiet_hours_start', '02:00'),
                'quiet_hours_end' => $settings->quiet_hours_end ?: (string) config('xai.default_quiet_hours_end', '11:00'),
                'has_api_key' => filled(config('xai.api_key')),
                'model' => (string) config('xai.model'),
                'credits' => $this->billing->balance(),
                'conversations' => $conversations,
            ],
        ]);
    }

    public function update(UpdateAiChatSettingsRequest $request): JsonResponse
    {
        $admin = $request->user();

        AiChatSetting::query()->updateOrCreate(
            ['admin_id' => $admin->id],
            [
                'enabled' => $request->boolean('enabled'),
                'scope' => $request->input('scope'),
                'system_prompt' => $request->input('system_prompt'),
                'reply_delay_minutes' => $request->integer('reply_delay_minutes'),
                'takeover_minutes' => $request->integer('takeover_minutes'),
                'quiet_hours_enabled' => $request->boolean('quiet_hours_enabled'),
                'quiet_hours_start' => $request->input('quiet_hours_start'),
                'quiet_hours_end' => $request->input('quiet_hours_end'),
            ]
        );

        return $this->show($request);
    }

    public function credits(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode ver o saldo.'], 403);
        }

        return response()->json([
            'data' => $this->billing->balance(fresh: true),
        ]);
    }

    public function toggleConversation(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();

        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode alterar isso.'], 403);
        }

        $conversation = Conversation::query()
            ->where('admin_id', $admin->id)
            ->findOrFail($id);

        $enabled = $request->has('ai_enabled')
            ? $request->boolean('ai_enabled')
            : ! $conversation->ai_enabled;

        $this->aiChat->setConversationAiEnabled($conversation, $enabled);

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'ai_enabled' => (bool) $conversation->ai_enabled,
                'ai_blocked' => $conversation->isAiBlocked(),
                'ai_blocked_reason' => $conversation->ai_blocked_reason,
            ],
        ]);
    }
}

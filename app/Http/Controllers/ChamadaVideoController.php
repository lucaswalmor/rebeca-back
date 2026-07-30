<?php

namespace App\Http\Controllers;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Http\Resources\MessageResource;
use App\Models\ChamadaVideo;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChamadaVideoController extends Controller
{
    public function store(Request $request, int $id)
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode agendar chamadas de vídeo.'], 403);
        }

        $conversation = Conversation::query()->findOrFail($id);

        if (! $conversation->isParticipant($user)) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $data = $request->validate([
            'titulo' => 'nullable|string|max:200',
            'data' => 'required|date',
            'horario' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'valor' => 'required|numeric|min:1.01',
            'duracao_minutos' => 'required|integer|min:1|max:480',
            'meet_link' => 'nullable|url|max:500',
        ], [
            'valor.min' => 'O valor mínimo da chamada é R$ 1,01 (limite da InfinitePay).',
        ]);

        $titulo = trim((string) ($data['titulo'] ?? '')) ?: 'Chamada de vídeo com a beca';
        $valor = round((float) $data['valor'], 2);

        $chamada = ChamadaVideo::create([
            'uuid' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'admin_id' => $conversation->admin_id,
            'subscriber_id' => $conversation->subscriber_id,
            'titulo' => $titulo,
            'data' => $data['data'],
            'horario' => $data['horario'],
            'duracao_minutos' => (int) $data['duracao_minutos'],
            'valor' => $valor,
            'meet_link' => $data['meet_link'] ?? null,
            'status' => 'pendente',
        ]);

        $orderNsu = 'videocal-'.$chamada->uuid;
        $chamada->update(['order_nsu' => $orderNsu]);

        $payload = [
            'handle' => config('services.infinitepay.handle'),
            'redirect_url' => rtrim(config('services.infinitepay.frontend_url'), '/').'/checkout/success',
            'webhook_url' => config('services.infinitepay.webhook_url'),
            'order_nsu' => $orderNsu,
            'items' => [
                [
                    'quantity' => 1,
                    'price' => intval(round($valor * 100)),
                    'description' => $titulo.' - '.$data['data'].' '.$data['horario'],
                ],
            ],
        ];

        Log::info('[CHAT] Payload InfinitePay chamada vídeo:', [
            'payload' => $payload,
            'chamada_id' => $chamada->id,
        ]);

        $response = Http::post('https://api.infinitepay.io/invoices/public/checkout/links', $payload);

        if (! $response->successful()) {
            $chamada->delete();
            Log::error('[CHAT] Erro InfinitePay chamada vídeo:', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar link de pagamento',
                'error' => $response->body(),
            ], 400);
        }

        $responseData = $response->json();
        $link = $this->extractLink($responseData);

        if (! $link) {
            $chamada->delete();

            return response()->json([
                'success' => false,
                'message' => 'Link de pagamento não encontrado na resposta da API',
            ], 400);
        }

        $chamada->update(['link_pagamento' => $link]);

        $message = $this->createCardMessage($chamada->fresh(), 'invoice');

        ChatLogger::info('Video call invoice sent', [
            'chamada_id' => $chamada->id,
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
        ]);

        return (new MessageResource($message))->additional([
            'success' => true,
            'chamada_video' => $chamada->fresh(),
        ]);
    }

    public static function markPaidAndNotify(ChamadaVideo $chamada): Message
    {
        return app(self::class)->createCardMessage($chamada->fresh(), 'receipt');
    }

    public function createCardMessage(ChamadaVideo $chamada, string $cardKind): Message
    {
        $conversation = $chamada->conversation()->firstOrFail();
        $admin = $chamada->admin()->first() ?: ChatLogger::adminUser();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $admin?->id ?? $conversation->admin_id,
            'type' => 'video_call',
            'body' => json_encode($chamada->toCardPayload($cardKind), JSON_UNESCAPED_UNICODE),
            'delivered_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);
        $message->load(['user', 'replyTo.user', 'likes']);

        broadcast(new MessageSent($message))->toOthers();
        broadcast(new ConversationUpdated(
            $conversation->id,
            $conversation->admin_id,
            $conversation->subscriber_id,
            [
                'unread_bump' => true,
                'sender' => [
                    'id' => $message->user_id,
                    'nome' => $message->user?->nome,
                    'apelido' => $message->user?->apelido,
                ],
                'latest_message' => [
                    'id' => $message->id,
                    'type' => $message->type,
                    'body' => 'Chamada de vídeo',
                    'user_id' => $message->user_id,
                    'conversation_id' => $conversation->id,
                    'created_at' => $message->created_at?->toIso8601String(),
                ],
            ]
        ));

        return $message;
    }

    private function extractLink(array $data): ?string
    {
        $possibleKeys = ['link', 'url', 'checkout_link', 'checkout_url', 'payment_url', 'redirect_url'];

        foreach ($possibleKeys as $key) {
            if (isset($data[$key])) {
                return $data[$key];
            }
        }

        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($possibleKeys as $key) {
                if (isset($data['data'][$key])) {
                    return $data['data'][$key];
                }
            }
        }

        return null;
    }
}

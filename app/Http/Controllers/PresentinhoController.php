<?php

namespace App\Http\Controllers;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Presentinho;
use App\Services\ChatLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PresentinhoController extends Controller
{
    /**
     * Admin: cria oferta de presentinho no chat (cliente paga depois).
     */
    public function offer(Request $request, int $id)
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode gerar Pix de presentinho.'], 403);
        }

        $conversation = Conversation::query()->findOrFail($id);

        if (! $conversation->isParticipant($user)) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $data = $request->validate([
            'valor' => 'required|numeric|min:1.01',
        ], [
            'valor.min' => 'O valor mínimo é R$ 1,01.',
        ]);

        $valor = round((float) $data['valor'], 2);

        $payload = [
            'valor' => $valor,
            'titulo' => 'Presentinho',
            'card_kind' => 'offer',
        ];

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'type' => 'presentinho_offer',
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'delivered_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);
        $message->load(['user', 'replyTo.user', 'likes']);

        $valorLabel = 'R$ '.number_format($valor, 2, ',', '.');

        broadcast(new MessageSent($message))->toOthers();
        broadcast(new ConversationUpdated(
            $conversation->id,
            $conversation->admin_id,
            $conversation->subscriber_id,
            [
                'unread_bump' => true,
                'sender' => [
                    'id' => $user->id,
                    'nome' => $user->nome,
                    'apelido' => $user->apelido,
                ],
                'latest_message' => [
                    'id' => $message->id,
                    'type' => $message->type,
                    'body' => "Presentinho de {$valorLabel}",
                    'user_id' => $message->user_id,
                    'conversation_id' => $conversation->id,
                    'created_at' => $message->created_at?->toIso8601String(),
                ],
            ]
        ));

        ChatLogger::info('Presentinho offer sent', [
            'message_id' => $message->id,
            'valor' => $valor,
            'conversation_id' => $conversation->id,
        ]);

        return (new MessageResource($message))->additional([
            'success' => true,
        ]);
    }

    /**
     * Cliente: gera link InfinitePay (slider livre ou oferta da admin).
     */
    public function store(Request $request, int $id)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return response()->json(['message' => 'A administradora não paga presentinho.'], 422);
        }

        if (! $user->hasAssinaturaAprovadaAtiva()) {
            return response()->json([
                'message' => 'Sua assinatura não está ativa.',
                'requires_subscription' => true,
            ], 403);
        }

        $conversation = Conversation::query()->findOrFail($id);

        if (! $conversation->isParticipant($user)) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        if ((int) $conversation->subscriber_id !== (int) $user->id) {
            return response()->json(['message' => 'Apenas o assinante desta conversa pode enviar presentinho.'], 403);
        }

        $data = $request->validate([
            'valor' => 'nullable|numeric|min:1.01',
            'offer_message_id' => 'nullable|integer|exists:messages,id',
        ]);

        $valor = null;

        if (! empty($data['offer_message_id'])) {
            $offer = Message::query()
                ->where('id', $data['offer_message_id'])
                ->where('conversation_id', $conversation->id)
                ->where('type', 'presentinho_offer')
                ->first();

            if (! $offer) {
                return response()->json(['message' => 'Oferta de presentinho inválida.'], 422);
            }

            $offerPayload = json_decode((string) $offer->body, true) ?: [];
            $valor = round((float) ($offerPayload['valor'] ?? 0), 2);

            if ($valor < 1.01) {
                return response()->json(['message' => 'Valor da oferta inválido. Mínimo R$ 1,01.'], 422);
            }
        } else {
            $valor = round((float) ($data['valor'] ?? 0), 2);

            if ($valor < 1.01) {
                return response()->json([
                    'message' => 'O valor mínimo do presentinho é R$ 1,01.',
                    'errors' => ['valor' => ['Informe um valor de no mínimo R$ 1,01.']],
                ], 422);
            }
        }

        $cents = (int) round($valor * 100);

        Presentinho::query()
            ->where('subscriber_id', $user->id)
            ->where('status', 'pendente')
            ->update(['status' => 'cancelado']);

        $presentinho = Presentinho::create([
            'uuid' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'admin_id' => $conversation->admin_id,
            'subscriber_id' => $conversation->subscriber_id,
            'valor' => $valor,
            'status' => 'pendente',
        ]);

        $orderNsu = 'presentinho-'.$presentinho->uuid;
        $presentinho->update(['order_nsu' => $orderNsu]);

        $payload = [
            'handle' => config('services.infinitepay.handle'),
            'redirect_url' => rtrim(config('services.infinitepay.frontend_url'), '/').'/checkout/success',
            'webhook_url' => config('services.infinitepay.webhook_url'),
            'order_nsu' => $orderNsu,
            'items' => [
                [
                    'quantity' => 1,
                    'price' => $cents,
                    'description' => 'Presentinho para a Beca - R$ '.number_format($valor, 2, ',', '.'),
                ],
            ],
        ];

        Log::info('[CHAT] Payload InfinitePay presentinho:', [
            'payload' => $payload,
            'presentinho_id' => $presentinho->id,
        ]);

        $response = Http::post('https://api.infinitepay.io/invoices/public/checkout/links', $payload);

        if (! $response->successful()) {
            $presentinho->delete();
            Log::error('[CHAT] Erro InfinitePay presentinho:', [
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
            $presentinho->delete();

            return response()->json([
                'success' => false,
                'message' => 'Link de pagamento não encontrado na resposta da API',
            ], 400);
        }

        $presentinho->update(['link_pagamento' => $link]);

        ChatLogger::info('Presentinho checkout created', [
            'presentinho_id' => $presentinho->id,
            'valor' => $valor,
            'conversation_id' => $conversation->id,
            'from_offer' => ! empty($data['offer_message_id']),
        ]);

        return response()->json([
            'success' => true,
            'link' => $link,
            'order_nsu' => $orderNsu,
            'valor' => $valor,
            'presentinho_id' => $presentinho->id,
        ]);
    }

    public static function markPaidAndNotify(Presentinho $presentinho): Message
    {
        return app(self::class)->createCardMessage($presentinho->fresh());
    }

    public function createCardMessage(Presentinho $presentinho): Message
    {
        $conversation = $presentinho->conversation()->firstOrFail();
        $subscriber = $presentinho->subscriber()->first();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $subscriber?->id ?? $conversation->subscriber_id,
            'type' => 'presentinho',
            'body' => json_encode($presentinho->toCardPayload(), JSON_UNESCAPED_UNICODE),
            'delivered_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);
        $message->load(['user', 'replyTo.user', 'likes']);

        $valorLabel = 'R$ '.number_format((float) $presentinho->valor, 2, ',', '.');

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
                    'body' => "Presentinho de {$valorLabel}",
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

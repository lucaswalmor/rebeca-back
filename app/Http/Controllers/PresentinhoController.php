<?php

namespace App\Http\Controllers;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
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
    public function store(Request $request, int $id)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return response()->json(['message' => 'A administradora não envia presentinho.'], 422);
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
            'valor' => 'required|numeric|min:50|max:50000',
        ], [
            'valor.min' => 'O valor mínimo do presentinho é R$ 50,00.',
            'valor.max' => 'O valor máximo do presentinho é R$ 50.000,00.',
        ]);

        $valor = round((float) $data['valor'], 2);
        $cents = (int) round($valor * 100);
        $minCents = 5000;
        $stepCents = 2000;

        if (($cents - $minCents) % $stepCents !== 0) {
            return response()->json([
                'message' => 'O valor do presentinho deve ser de R$ 50,00 em passos de R$ 20,00.',
                'errors' => ['valor' => ['Use valores como R$ 50, R$ 70, R$ 90…']],
            ], 422);
        }

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

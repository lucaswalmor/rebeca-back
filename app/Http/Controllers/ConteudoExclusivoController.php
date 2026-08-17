<?php

namespace App\Http\Controllers;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\ConteudoExclusivo;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ConteudoExclusivoController extends Controller
{
    public function store(Request $request, int $id)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return response()->json(['message' => 'A administradora não compra conteúdo exclusivo.'], 422);
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
            return response()->json(['message' => 'Apenas o assinante desta conversa pode pedir conteúdo exclusivo.'], 403);
        }

        $data = $request->validate([
            'tipo' => 'required|in:image,video',
        ]);

        $tipo = $data['tipo'];
        $admin = ChatLogger::adminUser();
        $valor = $tipo === 'video'
            ? (float) ($admin?->valor_video_exclusivo_chat ?? 0)
            : (float) ($admin?->valor_imagem_exclusiva_chat ?? 0);

        if ($valor < 1.01) {
            $label = $tipo === 'video' ? 'vídeo' : 'imagem';

            return response()->json([
                'message' => "O valor da {$label} exclusiva ainda não foi configurado pela administradora.",
            ], 422);
        }

        $valor = round($valor, 2);
        $cents = (int) round($valor * 100);

        ConteudoExclusivo::query()
            ->where('subscriber_id', $user->id)
            ->where('status', 'pendente')
            ->update(['status' => 'cancelado']);

        $pedido = ConteudoExclusivo::create([
            'uuid' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'admin_id' => $conversation->admin_id,
            'subscriber_id' => $conversation->subscriber_id,
            'tipo' => $tipo,
            'valor' => $valor,
            'status' => 'pendente',
        ]);

        $orderNsu = 'exclusivo-'.$pedido->uuid;
        $pedido->update(['order_nsu' => $orderNsu]);

        $descricao = $tipo === 'video'
            ? 'Vídeo exclusivo (até 1 min) - chat Beca'
            : 'Imagem exclusiva - chat Beca';

        $payload = [
            'handle' => config('services.infinitepay.handle'),
            'redirect_url' => rtrim(config('services.infinitepay.frontend_url'), '/').'/checkout/success',
            'webhook_url' => config('services.infinitepay.webhook_url'),
            'order_nsu' => $orderNsu,
            'items' => [
                [
                    'quantity' => 1,
                    'price' => $cents,
                    'description' => $descricao,
                ],
            ],
        ];

        Log::info('[CHAT] Payload InfinitePay conteúdo exclusivo:', [
            'payload' => $payload,
            'conteudo_exclusivo_id' => $pedido->id,
        ]);

        $response = Http::post(config('services.infinitepay.links_url'), $payload);

        if (! $response->successful()) {
            $pedido->delete();
            Log::error('[CHAT] Erro InfinitePay conteúdo exclusivo:', [
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
            $pedido->delete();

            return response()->json([
                'success' => false,
                'message' => 'Link de pagamento não encontrado na resposta da API',
            ], 400);
        }

        $pedido->update(['link_pagamento' => $link]);

        ChatLogger::info('Conteudo exclusivo checkout created', [
            'conteudo_exclusivo_id' => $pedido->id,
            'tipo' => $tipo,
            'valor' => $valor,
            'conversation_id' => $conversation->id,
        ]);

        return response()->json([
            'success' => true,
            'link' => $link,
            'order_nsu' => $orderNsu,
            'tipo' => $tipo,
            'valor' => $valor,
            'conteudo_exclusivo_id' => $pedido->id,
        ]);
    }

    public static function markPaidAndNotify(ConteudoExclusivo $pedido): Message
    {
        return app(self::class)->createCardMessage($pedido->fresh());
    }

    public function createCardMessage(ConteudoExclusivo $pedido): Message
    {
        $conversation = $pedido->conversation()->firstOrFail();
        $subscriber = $pedido->subscriber()->first();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $subscriber?->id ?? $conversation->subscriber_id,
            'type' => 'conteudo_exclusivo',
            'body' => json_encode($pedido->toCardPayload(), JSON_UNESCAPED_UNICODE),
            'delivered_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);
        $message->load(['user', 'replyTo.user', 'likes']);

        $preview = $pedido->tipoLabel();

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
                    'body' => $preview,
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

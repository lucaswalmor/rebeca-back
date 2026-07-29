<?php

namespace App\Http\Controllers;

use App\Models\ChatMediaPurchase;
use App\Services\ChatLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatMediaPurchaseController extends Controller
{
    public function gerarLink(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return response()->json(['message' => 'Administradora não precisa comprar créditos.'], 422);
        }

        if (! $user->hasAssinaturaAprovadaAtiva()) {
            return response()->json([
                'message' => 'Sua assinatura não está ativa. Renove para liberar envio de mídia.',
                'requires_subscription' => true,
            ], 403);
        }

        $admin = ChatLogger::adminUser();
        $valor = (float) ($admin?->valor_pacote_midia_chat ?? 0);

        if ($valor <= 0) {
            return response()->json([
                'message' => 'Pacote de mídia ainda não foi configurado pela administradora.',
            ], 422);
        }

        $credits = (int) config('chat.media_credits_per_pack', 5);

        $existente = ChatMediaPurchase::query()
            ->where('user_id', $user->id)
            ->where('status', 'pendente')
            ->whereNotNull('link_pagamento')
            ->latest()
            ->first();

        if ($existente) {
            return response()->json([
                'success' => true,
                'link' => $existente->link_pagamento,
                'purchase_id' => $existente->id,
                'order_nsu' => $existente->order_nsu,
                'reutilizado' => true,
                'credits' => $existente->credits,
                'valor' => $existente->valor,
            ]);
        }

        $purchase = ChatMediaPurchase::create([
            'user_id' => $user->id,
            'valor' => $valor,
            'credits' => $credits,
            'status' => 'pendente',
        ]);

        $orderNsu = 'chatmedia-'.$purchase->id.'-'.time();
        $purchase->update(['order_nsu' => $orderNsu]);

        $payload = [
            'handle' => 'rehantunes06',
            'redirect_url' => 'https://becalima007.vercel.app/checkout/success',
            'webhook_url' => 'https://rebeca.lksoftware.com.br/public/api/webhooks/infinitepay',
            'order_nsu' => $orderNsu,
            'items' => [
                [
                    'quantity' => 1,
                    'price' => intval($valor * 100),
                    'description' => "Pacote chat - {$credits} envios de foto/vídeo",
                ],
            ],
        ];

        Log::info('[CHAT] Payload InfinitePay pacote mídia:', [
            'payload' => $payload,
            'user_id' => $user->id,
        ]);

        $response = Http::post('https://api.infinitepay.io/invoices/public/checkout/links', $payload);

        if (! $response->successful()) {
            $purchase->delete();
            Log::error('[CHAT] Erro InfinitePay pacote mídia:', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar link de pagamento',
                'error' => $response->body(),
            ], 400);
        }

        $data = $response->json();
        $link = $this->extractLink($data);

        if (! $link) {
            $purchase->delete();

            return response()->json([
                'success' => false,
                'message' => 'Link de pagamento não encontrado na resposta da API',
                'response_data' => $data,
            ], 400);
        }

        $purchase->update(['link_pagamento' => $link]);

        return response()->json([
            'success' => true,
            'link' => $link,
            'purchase_id' => $purchase->id,
            'order_nsu' => $orderNsu,
            'credits' => $credits,
            'valor' => $valor,
        ]);
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

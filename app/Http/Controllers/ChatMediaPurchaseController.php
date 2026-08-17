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

        $data = $request->validate([
            'package_type' => 'required|in:media,audio',
            'quantity' => 'required|integer|min:1|max:99',
        ]);

        $packageType = $data['package_type'];
        $quantity = (int) $data['quantity'];

        $admin = ChatLogger::adminUser();
        $unitPrice = $packageType === 'audio'
            ? (float) ($admin?->valor_pacote_audio_chat ?? 0)
            : (float) ($admin?->valor_pacote_midia_chat ?? 0);

        if ($unitPrice <= 0) {
            $label = $packageType === 'audio' ? 'áudio' : 'foto/vídeo';

            return response()->json([
                'message' => "Pacote de {$label} ainda não foi configurado pela administradora.",
            ], 422);
        }

        $creditsPerPack = $packageType === 'audio'
            ? (int) config('chat.audio_credits_per_pack', 5)
            : (int) config('chat.media_credits_per_pack', 5);

        $credits = $creditsPerPack * $quantity;
        $valorTotal = round($unitPrice * $quantity, 2);

        ChatMediaPurchase::query()
            ->where('user_id', $user->id)
            ->where('status', 'pendente')
            ->update(['status' => 'cancelado']);

        $purchase = ChatMediaPurchase::create([
            'user_id' => $user->id,
            'package_type' => $packageType,
            'valor' => $valorTotal,
            'credits' => $credits,
            'quantity' => $quantity,
            'status' => 'pendente',
        ]);

        $prefix = $packageType === 'audio' ? 'chataudio' : 'chatmedia';
        $orderNsu = $prefix.'-'.$purchase->id.'-'.time();
        $purchase->update(['order_nsu' => $orderNsu]);

        $kindLabel = $packageType === 'audio' ? 'áudio' : 'foto/vídeo';
        $description = $quantity === 1
            ? "Pacote chat - {$creditsPerPack} envios de {$kindLabel}"
            : "{$quantity} pacotes chat - {$credits} envios de {$kindLabel}";

        $payload = [
            'handle' => config('services.infinitepay.handle'),
            'redirect_url' => rtrim(config('services.infinitepay.frontend_url'), '/').'/checkout/success',
            'webhook_url' => config('services.infinitepay.webhook_url'),
            'order_nsu' => $orderNsu,
            'items' => [
                [
                    'quantity' => $quantity,
                    'price' => intval(round($unitPrice * 100)),
                    'description' => $description,
                ],
            ],
        ];

        Log::info('[CHAT] Payload InfinitePay pacote chat:', [
            'payload' => $payload,
            'user_id' => $user->id,
            'package_type' => $packageType,
        ]);

        $response = Http::post(config('services.infinitepay.links_url'), $payload);

        if (! $response->successful()) {
            $purchase->delete();
            Log::error('[CHAT] Erro InfinitePay pacote chat:', [
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
            $purchase->delete();

            return response()->json([
                'success' => false,
                'message' => 'Link de pagamento não encontrado na resposta da API',
                'response_data' => $responseData,
            ], 400);
        }

        $purchase->update(['link_pagamento' => $link]);

        return response()->json([
            'success' => true,
            'link' => $link,
            'purchase_id' => $purchase->id,
            'order_nsu' => $orderNsu,
            'package_type' => $packageType,
            'quantity' => $quantity,
            'credits' => $credits,
            'credits_per_pack' => $creditsPerPack,
            'unit_price' => $unitPrice,
            'valor' => $valorTotal,
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

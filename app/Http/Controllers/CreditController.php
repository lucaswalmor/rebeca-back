<?php

namespace App\Http\Controllers;

use App\Models\CreditPurchase;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CreditController extends Controller
{
    /** @var list<int|float> */
    public const CHIPS = [20, 50, 100, 200, 500, 1000];

    public function __construct(private CreditService $credits) {}

    public function saldo(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'creditos' => $this->credits->balance($user),
            'chips' => self::CHIPS,
            'chat_media_cost' => $this->credits->chatSendCost('media'),
            'chat_audio_cost' => $this->credits->chatSendCost('audio'),
            'chat_media_credits' => (int) $user->chat_media_credits,
            'chat_audio_credits' => (int) $user->chat_audio_credits,
        ]);
    }

    public function gerarLinkRecarga(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return response()->json(['message' => 'Administradora não precisa recarregar créditos.'], 422);
        }

        $data = $request->validate([
            'valor' => 'required|numeric|min:1|max:10000',
        ]);

        $valor = round((float) $data['valor'], 2);
        $isChip = collect(self::CHIPS)->contains(fn ($chip) => abs((float) $chip - $valor) < 0.001);

        if (! $isChip && $valor < 20) {
            return response()->json([
                'message' => 'O valor mínimo de recarga é R$ 20,00.',
            ], 422);
        }

        CreditPurchase::query()
            ->where('user_id', $user->id)
            ->where('status', 'pendente')
            ->update(['status' => 'cancelado']);

        $purchase = CreditPurchase::create([
            'user_id' => $user->id,
            'valor' => $valor,
            'status' => 'pendente',
        ]);

        $orderNsu = 'credito-'.$purchase->id.'-'.time();
        $purchase->update(['order_nsu' => $orderNsu]);

        $payload = [
            'handle' => config('services.infinitepay.handle'),
            'redirect_url' => rtrim(config('services.infinitepay.frontend_url'), '/').'/checkout/success',
            'webhook_url' => config('services.infinitepay.webhook_url'),
            'order_nsu' => $orderNsu,
            'items' => [
                [
                    'quantity' => 1,
                    'price' => (int) round($valor * 100),
                    'description' => 'Recarga de créditos - R$ '.number_format($valor, 2, ',', '.'),
                ],
            ],
        ];

        Log::info('[CREDITOS] Payload InfinitePay recarga:', [
            'payload' => $payload,
            'user_id' => $user->id,
            'purchase_id' => $purchase->id,
        ]);

        $response = Http::post('https://api.infinitepay.io/invoices/public/checkout/links', $payload);

        if (! $response->successful()) {
            $purchase->delete();
            Log::error('[CREDITOS] Erro InfinitePay recarga:', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar link de pagamento.',
            ], 400);
        }

        $responseData = $response->json();
        $link = $this->extractLink($responseData);

        if (! $link) {
            $purchase->delete();

            return response()->json([
                'success' => false,
                'message' => 'Link de pagamento não encontrado na resposta da API.',
                'response_data' => $responseData,
            ], 400);
        }

        $purchase->update(['link_pagamento' => $link]);

        return response()->json([
            'success' => true,
            'link' => $link,
            'purchase_id' => $purchase->id,
            'order_nsu' => $orderNsu,
            'valor' => $valor,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractLink(array $data): ?string
    {
        $possibleKeys = ['link', 'url', 'checkout_link', 'checkout_url', 'payment_url', 'redirect_url'];

        foreach ($possibleKeys as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($possibleKeys as $key) {
                if (isset($data['data'][$key]) && is_string($data['data'][$key])) {
                    return $data['data'][$key];
                }
            }
        }

        return null;
    }
}

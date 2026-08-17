<?php

namespace App\Http\Controllers;

use App\Models\CreditPurchase;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CreditController extends Controller
{
    /**
     * Chips de recarga (créditos = valor em R$, exceto chip de teste).
     * TEMP: chip 1 credita 1 crédito e cobra R$ 2,00 só para testes.
     *
     * @var list<int|float>
     */
    public const CHIPS = [1, 20, 50, 100, 200, 500, 1000];

    /** Preço em R$ cobrado no InfinitePay por chip (padrão 1:1). */
    private function payAmount(float $chip): float
    {
        // TEMP teste: 1 crédito por R$ 2,00
        if (abs($chip - 1.0) < 0.001) {
            return 2.0;
        }

        return round($chip, 2);
    }

    public function __construct(private CreditService $credits) {}

    public function saldo(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'creditos' => $this->credits->balance($user),
            'chips' => self::CHIPS,
            'chip_prices' => collect(self::CHIPS)
                ->mapWithKeys(fn ($chip) => [(string) $chip => $this->payAmount((float) $chip)])
                ->all(),
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

        $chip = round((float) $data['valor'], 2);
        $isChip = collect(self::CHIPS)->contains(fn ($c) => abs((float) $c - $chip) < 0.001);

        if (! $isChip && $chip < 20) {
            return response()->json([
                'message' => 'O valor mínimo de recarga é R$ 20,00.',
            ], 422);
        }

        $payAmount = $this->payAmount($chip);

        CreditPurchase::query()
            ->where('user_id', $user->id)
            ->where('status', 'pendente')
            ->update(['status' => 'cancelado']);

        // `valor` = créditos a creditar (chip). Cobrança InfinitePay usa $payAmount.
        $purchase = CreditPurchase::create([
            'user_id' => $user->id,
            'valor' => $chip,
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
                    'price' => (int) round($payAmount * 100),
                    'description' => 'Recarga de créditos - R$ '.number_format($payAmount, 2, ',', '.'),
                ],
            ],
        ];

        Log::info('[CREDITOS] Payload InfinitePay recarga:', [
            'payload' => $payload,
            'user_id' => $user->id,
            'purchase_id' => $purchase->id,
            'chip' => $chip,
            'pay_amount' => $payAmount,
        ]);

        $response = Http::post(config('services.infinitepay.links_url'), $payload);

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
            'valor' => $chip,
            'pay_amount' => $payAmount,
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

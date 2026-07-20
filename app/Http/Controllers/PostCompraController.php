<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostCompra;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostCompraController extends Controller
{
    /**
     * Gera link de pagamento InfinitePay para comprar o conteúdo do post.
     * Exige assinatura ativa. Compra fica vinculada ao user_id + post_id.
     */
    public function comprar(Request $request, string $id)
    {
        $user = Auth::user();
        $post = Post::findOrFail($id);

        if (! $user->hasAssinaturaAprovadaAtiva() && ! $user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'É necessário ter uma assinatura ativa para comprar conteúdos.',
            ], 403);
        }

        if ((float) $post->preco <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Este conteúdo ainda não possui preço definido.',
            ], 422);
        }

        $compraAprovada = PostCompra::where('user_id', $user->id)
            ->where('post_id', $post->id)
            ->where('status', 'aprovado')
            ->first();

        if ($compraAprovada) {
            return response()->json([
                'success' => false,
                'message' => 'Você já comprou este conteúdo.',
            ], 422);
        }

        // Reutilizar compra pendente existente (com ou sem link)
        $compra = PostCompra::where('user_id', $user->id)
            ->where('post_id', $post->id)
            ->where('status', 'pendente')
            ->first();

        if ($compra && $compra->link_pagamento) {
            return response()->json([
                'success' => true,
                'link' => $compra->link_pagamento,
                'compra_id' => $compra->id,
                'order_nsu' => $compra->order_nsu,
            ]);
        }

        if (! $compra) {
            $compra = PostCompra::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'status' => 'pendente',
                'valor' => $post->preco,
            ]);
        }

        $orderNsu = 'post-'.$compra->id.'-'.time();
        $compra->update([
            'order_nsu' => $orderNsu,
            'valor' => $post->preco,
        ]);

        $payload = [
            'handle' => 'rehantunes06',
            'redirect_url' => 'https://becalima007.vercel.app/checkout/success',
            'webhook_url' => 'https://rebeca.lksoftware.com.br/public/api/webhooks/infinitepay',
            'order_nsu' => $orderNsu,
            'items' => [
                [
                    'quantity' => 1,
                    'price' => (int) round(((float) $post->preco) * 100),
                    'description' => 'Conteúdo exclusivo #'.$post->id,
                ],
            ],
        ];

        Log::info('Payload InfinitePay compra de post:', [
            'payload' => $payload,
            'post_id' => $post->id,
            'user_id' => $user->id,
        ]);

        $response = Http::post('https://api.infinitepay.io/invoices/public/checkout/links', $payload);

        if (! $response->successful()) {
            Log::error('Erro InfinitePay compra de post:', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar link de pagamento.',
            ], 400);
        }

        $data = $response->json();
        $link = $this->extractCheckoutLink($data);

        if (! $link) {
            return response()->json([
                'success' => false,
                'message' => 'Link de pagamento não encontrado na resposta da API.',
                'response_data' => $data,
            ], 400);
        }

        $compra->update(['link_pagamento' => $link]);

        return response()->json([
            'success' => true,
            'link' => $link,
            'compra_id' => $compra->id,
            'order_nsu' => $orderNsu,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractCheckoutLink(array $data): ?string
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

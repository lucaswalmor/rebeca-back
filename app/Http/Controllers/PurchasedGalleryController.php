<?php

namespace App\Http\Controllers;

use App\Models\MessagePurchase;
use App\Models\PostCompra;
use Illuminate\Http\Request;

class PurchasedGalleryController extends Controller
{
    /**
     * Galeria do cliente: mídias de posts e chat já comprados.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $items = [];

        $compras = PostCompra::with(['post' => function ($q) {
            $q->withTrashed()->with('media');
        }])
            ->where('user_id', $user->id)
            ->where('status', 'aprovado')
            ->orderByDesc('payment_date')
            ->orderByDesc('updated_at')
            ->get();

        foreach ($compras as $compra) {
            $post = $compra->post;
            if (! $post) {
                continue;
            }

            $mediaList = $post->media
                ->where('is_preview', false)
                ->values();

            if ($mediaList->isEmpty()) {
                $mediaList = $post->media->values();
            }

            foreach ($mediaList as $i => $media) {
                $url = $this->buildMediaUrl($media->path);
                if (! $url) {
                    continue;
                }
                $items[] = [
                    'key' => "post-{$post->id}-{$media->id}",
                    'source' => 'post',
                    'post_id' => $post->id,
                    'message_id' => null,
                    'url' => $url,
                    'tipo' => $media->tipo === 'video' ? 'video' : 'image',
                    'created_at' => optional($compra->payment_date ?? $compra->updated_at ?? $post->created_at)->toISOString(),
                ];
            }
        }

        $messagePurchases = MessagePurchase::with('message')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        foreach ($messagePurchases as $purchase) {
            $message = $purchase->message;
            if (! $message || ! in_array($message->type, ['image', 'video'], true)) {
                continue;
            }

            $url = $message->media_url;
            if (! $url && $message->media_path) {
                $url = $this->buildMediaUrl($message->media_path);
            }
            if (! $url) {
                continue;
            }

            $items[] = [
                'key' => "chat-{$message->id}",
                'source' => 'chat',
                'post_id' => null,
                'message_id' => $message->id,
                'url' => $url,
                'tipo' => $message->type === 'video' ? 'video' : 'image',
                'created_at' => optional($purchase->created_at ?? $message->created_at)->toISOString(),
            ];
        }

        usort($items, function ($a, $b) {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return response()->json([
            'data' => array_values($items),
            'meta' => [
                'total' => count($items),
            ],
        ]);
    }

    private function buildMediaUrl(?string $path): string
    {
        if (! $path) {
            return '';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) env('AWS_URL'), '/').'/'.ltrim($path, '/');
    }
}

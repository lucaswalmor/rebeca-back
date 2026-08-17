<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GrokChatClient
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function complete(array $messages): ?string
    {
        $apiKey = (string) config('xai.api_key');
        $baseUrl = rtrim((string) config('xai.base_url'), '/');

        if ($apiKey === '' || $baseUrl === '') {
            Log::warning('[AI-CHAT] XAI_API_KEY ou XAI_BASE_URL ausente.');

            return null;
        }

        $response = Http::baseUrl($baseUrl)
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('xai.timeout', 60))
            ->post('chat/completions', [
                'model' => (string) config('xai.model', 'grok-4.3'),
                'temperature' => 0.9,
                'messages' => $messages,
            ]);

        if (! $response->successful()) {
            Log::error('[AI-CHAT] Grok recusou a requisição', [
                'status' => $response->status(),
                'body' => $this->truncate((string) $response->body()),
            ]);

            return null;
        }

        $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

        return $text !== '' ? $text : null;
    }

    private function truncate(string $value): string
    {
        return mb_substr($value, 0, 500);
    }
}

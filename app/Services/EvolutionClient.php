<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class EvolutionClient
{
    public function configured(): bool
    {
        return rtrim((string) config('evolution.base_url'), '/') !== ''
            && (string) config('evolution.api_key') !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        try {
            $response = $this->request()->get($path, $query)->throw();
        } catch (RequestException $e) {
            throw $this->fail($e, $path);
        }

        return $this->json($response->json());
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        try {
            $response = $this->request()->post($path, $body)->throw();
        } catch (RequestException $e) {
            throw $this->fail($e, $path);
        }

        return $this->json($response->json());
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $path): array
    {
        try {
            $response = $this->request()->delete($path)->throw();
        } catch (RequestException $e) {
            throw $this->fail($e, $path);
        }

        return $this->json($response->json());
    }

    public function sendText(string $instanceName, string $number, string $text): bool
    {
        if (! $this->configured() || $instanceName === '' || $number === '' || trim($text) === '') {
            return false;
        }

        try {
            $this->post('/message/sendText/'.$instanceName, [
                'number' => $number,
                'text' => $text,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('[EVOLUTION] Falha ao enviar texto', [
                'instance' => $instanceName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        $baseUrl = rtrim((string) config('evolution.base_url'), '/');
        $apiKey = (string) config('evolution.api_key');

        if ($baseUrl === '' || $apiKey === '') {
            throw ValidationException::withMessages([
                'evolution' => ['Evolution API não configurada. Defina EVOLUTION_BASE_URL e EVOLUTION_API_KEY no .env.'],
            ]);
        }

        return Http::withHeaders([
            'apikey' => $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])
            ->baseUrl($baseUrl)
            ->timeout((int) config('evolution.timeout', 30))
            ->acceptJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(mixed $json): array
    {
        return is_array($json) ? $json : [];
    }

    private function fail(RequestException $e, string $path): ValidationException
    {
        $status = $e->response?->status();
        $body = $e->response?->json();
        $detail = is_array($body)
            ? (string) ($body['message'] ?? $body['error'] ?? json_encode($body))
            : ($e->response?->body() ?? $e->getMessage());

        Log::error('[EVOLUTION] API recusou a requisição', [
            'path' => $path,
            'status' => $status,
            'detail' => mb_substr((string) $detail, 0, 500),
        ]);

        report(new RuntimeException("Evolution API falhou em {$path} [{$status}]: {$detail}", 0, $e));

        return ValidationException::withMessages([
            'evolution' => ['Falha ao comunicar com a Evolution API. Tente novamente em instantes.'],
        ]);
    }
}

<?php

namespace App\Services;

use App\Mail\GrokLowBalanceMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GrokBillingService
{
    /**
     * @return array{configured: bool, remaining_usd: float|null, remaining_cents: int|null, error: string|null}
     */
    public function balance(bool $fresh = false): array
    {
        $managementKey = (string) config('xai.management_key');

        if ($managementKey === '') {
            return [
                'configured' => false,
                'remaining_usd' => null,
                'remaining_cents' => null,
                'error' => null,
            ];
        }

        if ($fresh) {
            Cache::forget('xai.prepaid_balance');
        }

        return Cache::remember('xai.prepaid_balance', 60, fn () => $this->fetchBalance($managementKey));
    }

    /**
     * @return array{configured: bool, remaining_usd: float|null, remaining_cents: int|null, error: string|null}
     */
    private function fetchBalance(string $managementKey): array
    {
        $baseUrl = rtrim((string) config('xai.management_base_url', 'https://management-api.x.ai'), '/');
        $teamId = trim((string) config('xai.team_id'));

        try {
            if ($teamId === '') {
                $teamId = $this->resolveTeamId($baseUrl, $managementKey) ?? '';
            }

            if ($teamId === '') {
                return [
                    'configured' => true,
                    'remaining_usd' => null,
                    'remaining_cents' => null,
                    'error' => 'Team ID não encontrado.',
                ];
            }

            $response = Http::baseUrl($baseUrl)
                ->withToken($managementKey)
                ->acceptJson()
                ->timeout(15)
                ->get("v1/billing/teams/{$teamId}/prepaid/balance");

            if (! $response->successful()) {
                Log::warning('[AI-CHAT] Falha ao consultar saldo xAI', [
                    'status' => $response->status(),
                ]);

                return [
                    'configured' => true,
                    'remaining_usd' => null,
                    'remaining_cents' => null,
                    'error' => 'Não foi possível consultar o saldo agora.',
                ];
            }

            $cents = (int) data_get($response->json(), 'total.val', 0);
            $remainingCents = abs($cents);
            $remainingUsd = round($remainingCents / 100, 2);

            $this->notifyIfLow($remainingUsd);

            return [
                'configured' => true,
                'remaining_usd' => $remainingUsd,
                'remaining_cents' => $remainingCents,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('[AI-CHAT] Erro ao consultar saldo xAI', [
                'error' => $e->getMessage(),
            ]);

            return [
                'configured' => true,
                'remaining_usd' => null,
                'remaining_cents' => null,
                'error' => 'Não foi possível consultar o saldo agora.',
            ];
        }
    }

    private function resolveTeamId(string $baseUrl, string $managementKey): ?string
    {
        $response = Http::baseUrl($baseUrl)
            ->withToken($managementKey)
            ->acceptJson()
            ->timeout(15)
            ->get('auth/management-keys/validation');

        if (! $response->successful()) {
            return null;
        }

        $teamId = (string) (data_get($response->json(), 'scopeId')
            ?: data_get($response->json(), 'teamId')
            ?: '');

        return $teamId !== '' ? $teamId : null;
    }

    private function notifyIfLow(float $remainingUsd): void
    {
        $threshold = (float) config('xai.alert_below_usd', 1);
        $emails = config('xai.alert_emails', []);

        if (! is_array($emails) || $emails === []) {
            return;
        }

        if ($remainingUsd >= $threshold) {
            Cache::forget('xai.low_balance_alert_sent');

            return;
        }

        if (Cache::get('xai.low_balance_alert_sent')) {
            return;
        }

        try {
            Mail::to($emails)->send(new GrokLowBalanceMail($remainingUsd));
            Cache::forever('xai.low_balance_alert_sent', true);
        } catch (\Throwable $e) {
            Log::error('[AI-CHAT] Falha ao enviar alerta de saldo Grok', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

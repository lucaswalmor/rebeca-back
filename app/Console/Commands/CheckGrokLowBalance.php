<?php

namespace App\Console\Commands;

use App\Services\GrokBillingService;
use Illuminate\Console\Command;

class CheckGrokLowBalance extends Command
{
    protected $signature = 'xai:check-low-balance';

    protected $description = 'Consulta o prepaid da xAI e avisa por e-mail se estiver abaixo de US$ 1';

    public function handle(GrokBillingService $billing): int
    {
        $balance = $billing->balance(fresh: true);

        if (! $balance['configured']) {
            $this->warn('Management key da xAI não configurada.');

            return self::SUCCESS;
        }

        if ($balance['error']) {
            $this->error($balance['error']);

            return self::FAILURE;
        }

        $remaining = $balance['remaining_usd'];
        $this->info('Saldo atual: US$ '.number_format((float) $remaining, 2, '.', ''));

        return self::SUCCESS;
    }
}

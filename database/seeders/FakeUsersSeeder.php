<?php

namespace Database\Seeders;

use App\Services\FakeEngagementService;
use Illuminate\Database\Seeder;

class FakeUsersSeeder extends Seeder
{
    /**
     * Cria 50 usuários fakes assinantes para engajamento.
     */
    public function run(): void
    {
        $service = app(FakeEngagementService::class);
        $users = $service->ensureFakeUsers();

        $this->command?->info('Usuários fakes assinantes prontos: '.$users->count());
        $this->command?->info('Exemplos de apelidos: '.$users->take(5)->pluck('apelido')->implode(', '));

        $aindaFanRebeca = $users->filter(
            fn ($user) => str_starts_with((string) $user->apelido, 'fan_rebeca_')
        )->count();

        if ($aindaFanRebeca > 0) {
            $this->command?->error("Ainda existem {$aindaFanRebeca} apelidos fan_rebeca_* — verifique o deploy do código.");
        } else {
            $this->command?->info('Nenhum apelido fan_rebeca_* restante.');
        }
    }
}
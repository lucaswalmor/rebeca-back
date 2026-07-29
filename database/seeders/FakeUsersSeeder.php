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
    }
}

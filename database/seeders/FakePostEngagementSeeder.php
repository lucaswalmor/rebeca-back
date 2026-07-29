<?php

namespace Database\Seeders;

use App\Services\FakeEngagementService;
use Illuminate\Database\Seeder;

class FakePostEngagementSeeder extends Seeder
{
    /**
     * Aplica curtidas e comentários fakes nos posts que ainda não têm.
     */
    public function run(): void
    {
        $service = app(FakeEngagementService::class);
        $updated = $service->seedMissingPosts();

        $this->command?->info("Engajamento fake aplicado em {$updated} post(s).");
    }
}

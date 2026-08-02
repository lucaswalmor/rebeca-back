<?php

namespace Tests\Feature;

use App\Models\Assinatura;
use App\Models\Live;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LiveFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'nome' => 'Teste',
            'sobrenome' => 'User',
            'apelido' => 'teste'.uniqid(),
            'email' => uniqid('user_', true).'@example.com',
            'password' => bcrypt('password'),
            'telefone' => '11999999999',
            'data_nascimento' => '1990-01-01',
            'is_admin' => false,
            'creditos' => 0,
        ], $overrides));
    }

    private function giveActiveSubscription(User $user): void
    {
        Assinatura::create([
            'user_id' => $user->id,
            'status' => 'aprovado',
            'data_inicio' => now()->subDay()->toDateString(),
            'data_fim' => now()->addMonth()->toDateString(),
            'tipo_assinatura' => 'mensal',
            'valor' => 29.9,
            'plano' => '1_mes',
            'order_nsu' => 'order-live-'.uniqid(),
        ]);
    }

    public function test_admin_can_schedule_one_live(): void
    {
        $admin = $this->createUser(['is_admin' => true, 'apelido' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/lives', [
            'titulo' => 'Live de teste',
            'descricao' => 'Descrição',
            'starts_at' => now()->addDay()->toIso8601String(),
            'is_private' => false,
            'price_credits' => 0,
            'max_participants' => 50,
            'notify' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.titulo', 'Live de teste')
            ->assertJsonPath('data.status', 'agendada');

        $this->assertDatabaseCount('lives', 1);

        $second = $this->postJson('/api/admin/lives', [
            'titulo' => 'Outra live',
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'max_participants' => 10,
            'notify' => false,
        ]);

        $second->assertStatus(422);
    }

    public function test_subscriber_can_join_free_live_and_get_token(): void
    {
        Config::set('services.livekit.api_key', 'APItestkey');
        Config::set('services.livekit.api_secret', 'secretsecretsecretsecretsecret12');
        Config::set('services.livekit.url', 'wss://example.livekit.cloud');

        $admin = $this->createUser(['is_admin' => true, 'apelido' => 'admin']);
        $subscriber = $this->createUser(['apelido' => 'fan']);
        $this->giveActiveSubscription($subscriber);

        $live = Live::create([
            'admin_id' => $admin->id,
            'titulo' => 'Live grátis',
            'starts_at' => now()->addHour(),
            'is_private' => false,
            'price_credits' => 0,
            'max_participants' => 50,
            'status' => Live::STATUS_AGENDADA,
            'chat_enabled' => true,
        ]);

        Sanctum::actingAs($subscriber);

        $this->postJson('/api/lives/'.$live->uuid.'/join')
            ->assertOk()
            ->assertJsonPath('success', true);

        $token = $this->postJson('/api/lives/'.$live->uuid.'/token')
            ->assertOk()
            ->assertJsonStructure(['token', 'url', 'room', 'can_publish']);

        $this->assertFalse($token->json('can_publish'));
        $this->assertNotEmpty($token->json('token'));
    }

    public function test_paid_live_debits_credits(): void
    {
        $admin = $this->createUser(['is_admin' => true]);
        $subscriber = $this->createUser(['creditos' => 20]);
        $this->giveActiveSubscription($subscriber);

        $live = Live::create([
            'admin_id' => $admin->id,
            'titulo' => 'Live paga',
            'starts_at' => now()->addHour(),
            'is_private' => false,
            'price_credits' => 10,
            'max_participants' => 50,
            'status' => Live::STATUS_AO_VIVO,
            'chat_enabled' => true,
        ]);

        Sanctum::actingAs($subscriber);

        $this->postJson('/api/lives/'.$live->uuid.'/join')->assertOk();

        $this->assertEquals(10.0, (float) $subscriber->fresh()->creditos);
        $this->assertDatabaseHas('live_tickets', [
            'live_id' => $live->id,
            'user_id' => $subscriber->id,
            'credits_paid' => 10,
        ]);
    }

    public function test_private_live_blocks_non_invited(): void
    {
        $admin = $this->createUser(['is_admin' => true]);
        $subscriber = $this->createUser();
        $this->giveActiveSubscription($subscriber);

        $live = Live::create([
            'admin_id' => $admin->id,
            'titulo' => 'Privada',
            'starts_at' => now()->addHour(),
            'is_private' => true,
            'price_credits' => 0,
            'max_participants' => 10,
            'status' => Live::STATUS_AGENDADA,
        ]);

        Sanctum::actingAs($subscriber);

        $this->postJson('/api/lives/'.$live->uuid.'/join')
            ->assertForbidden();
    }
}

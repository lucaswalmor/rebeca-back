<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsAppInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppInstanceTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'nome' => 'Teste',
            'sobrenome' => 'User',
            'apelido' => 'teste'.uniqid(),
            'email' => uniqid('user_', true).'@example.com',
            'password' => bcrypt('password'),
            'telefone' => '11999999999',
            'data_nascimento' => '1990-01-01',
            'is_admin' => false,
            'chat_media_credits' => 0,
        ], $overrides));
    }

    public function test_subscriber_cannot_access_whatsapp_settings(): void
    {
        $subscriber = $this->createUser();
        Sanctum::actingAs($subscriber);

        $this->getJson('/api/admin/whatsapp')->assertForbidden();
    }

    public function test_admin_sees_unconfigured_whatsapp_without_instance(): void
    {
        $admin = $this->createUser(['is_admin' => true, 'email' => 'admin@example.com', 'apelido' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/whatsapp')
            ->assertSuccessful()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.instance', null);
    }

    public function test_admin_can_create_instance_and_receive_qr(): void
    {
        config([
            'evolution.base_url' => 'https://evolution.test',
            'evolution.api_key' => 'evo-key',
        ]);

        Http::fake([
            'https://evolution.test/instance/create' => Http::response([
                'qrcode' => ['base64' => 'data:image/png;base64,AAA'],
                'instance' => ['instanceId' => 'abc'],
            ], 200),
            'https://evolution.test/webhook/set/*' => Http::response(['ok' => true], 200),
            'https://evolution.test/instance/connectionState/*' => Http::response([
                'instance' => ['state' => 'connecting'],
            ], 200),
        ]);

        $admin = $this->createUser(['is_admin' => true, 'email' => 'admin@example.com', 'apelido' => 'admin']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/whatsapp/conectar')
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'aguardando_qr')
            ->assertJsonPath('data.qrcode_base64', 'data:image/png;base64,AAA');

        $this->assertDatabaseHas('whatsapp_instances', [
            'admin_id' => $admin->id,
            'status' => WhatsAppInstance::STATUS_AGUARDANDO_QR,
        ]);
    }

    public function test_webhook_marks_instance_as_connected(): void
    {
        $admin = $this->createUser(['is_admin' => true, 'email' => 'admin@example.com', 'apelido' => 'admin']);
        $instance = WhatsAppInstance::query()->create([
            'admin_id' => $admin->id,
            'nome_instancia' => 'rebeca_webhook',
            'status' => WhatsAppInstance::STATUS_AGUARDANDO_QR,
        ]);

        $this->postJson('/api/webhooks/evolution', [
            'event' => 'CONNECTION_UPDATE',
            'instance' => 'rebeca_webhook',
            'data' => [
                'state' => 'open',
                'instance' => [
                    'state' => 'open',
                    'ownerJid' => '5511999999999@s.whatsapp.net',
                ],
            ],
        ])->assertSuccessful();

        $instance->refresh();
        $this->assertSame(WhatsAppInstance::STATUS_CONECTADO, $instance->status);
        $this->assertSame('5511999999999', $instance->numero);
    }

    public function test_admin_can_save_notify_number_and_disconnect(): void
    {
        config([
            'evolution.base_url' => 'https://evolution.test',
            'evolution.api_key' => 'evo-key',
        ]);
        Http::fake([
            'https://evolution.test/*' => Http::response(['ok' => true], 200),
        ]);

        $admin = $this->createUser(['is_admin' => true, 'email' => 'admin@example.com', 'apelido' => 'admin']);
        WhatsAppInstance::query()->create([
            'admin_id' => $admin->id,
            'nome_instancia' => 'rebeca_test',
            'status' => WhatsAppInstance::STATUS_CONECTADO,
            'numero' => '5511999999999',
        ]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/whatsapp/notify-number', [
            'notify_number' => '(11) 98888-7777',
        ])->assertSuccessful()
            ->assertJsonPath('data.notify_number', '11988887777');

        $this->postJson('/api/admin/whatsapp/desconectar')
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'desconectado');
    }
}

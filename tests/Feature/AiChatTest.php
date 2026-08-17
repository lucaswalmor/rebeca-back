<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiChatReply;
use App\Models\AiChatSetting;
use App\Models\Assinatura;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AiChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiChatTest extends TestCase
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

    private function createActiveSubscription(User $user): Assinatura
    {
        return Assinatura::query()->create([
            'user_id' => $user->id,
            'data_inicio' => now()->subDay(),
            'data_fim' => now()->addMonth(),
            'tipo_assinatura' => 'mensal',
            'status' => 'aprovado',
            'valor' => 10,
            'plano' => '1_mes',
        ]);
    }

    /**
     * @return array{admin: User, subscriber: User, conversation: Conversation}
     */
    private function openChat(): array
    {
        $admin = $this->createUser([
            'is_admin' => true,
            'email' => 'admin@example.com',
            'apelido' => 'admin',
            'nome' => 'Beca',
        ]);
        $subscriber = $this->createUser(['nome' => 'Joao']);
        $this->createActiveSubscription($subscriber);

        Sanctum::actingAs($subscriber);
        $conversationId = $this->postJson('/api/chat/conversations/open')->json('data.id');

        return [
            'admin' => $admin,
            'subscriber' => $subscriber,
            'conversation' => Conversation::query()->findOrFail($conversationId),
        ];
    }

    private function enableAi(User $admin, Conversation $conversation, array $overrides = []): AiChatSetting
    {
        config(['xai.api_key' => 'test-key']);

        $settings = AiChatSetting::query()->create(array_merge([
            'admin_id' => $admin->id,
            'enabled' => true,
            'scope' => 'selected',
            'system_prompt' => null,
            'reply_delay_minutes' => 5,
            'takeover_minutes' => 15,
        ], $overrides));

        $conversation->forceFill(['ai_enabled' => true])->save();

        return $settings;
    }

    public function test_ai_does_not_dispatch_when_disabled_by_default(): void
    {
        Queue::fake();
        $chat = $this->openChat();

        $this->postJson("/api/chat/conversations/{$chat['conversation']->id}/messages", [
            'type' => 'text',
            'body' => 'Oi Beca',
        ])->assertSuccessful();

        Queue::assertNotPushed(GenerateAiChatReply::class);
    }

    public function test_ai_does_not_dispatch_for_unselected_conversation(): void
    {
        Queue::fake();
        config(['xai.api_key' => 'test-key']);
        $chat = $this->openChat();

        AiChatSetting::query()->create([
            'admin_id' => $chat['admin']->id,
            'enabled' => true,
            'scope' => 'selected',
            'reply_delay_minutes' => 5,
            'takeover_minutes' => 15,
        ]);

        $this->postJson("/api/chat/conversations/{$chat['conversation']->id}/messages", [
            'type' => 'text',
            'body' => 'Oi Beca',
        ])->assertSuccessful();

        Queue::assertNotPushed(GenerateAiChatReply::class);
    }

    public function test_ai_dispatches_for_selected_conversation(): void
    {
        Queue::fake();
        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation']);

        $this->postJson("/api/chat/conversations/{$chat['conversation']->id}/messages", [
            'type' => 'text',
            'body' => 'Oi Beca',
        ])->assertSuccessful();

        Queue::assertPushed(GenerateAiChatReply::class);
    }

    public function test_admin_message_records_human_takeover(): void
    {
        $chat = $this->openChat();
        Sanctum::actingAs($chat['admin']);

        $this->postJson("/api/chat/conversations/{$chat['conversation']->id}/messages", [
            'type' => 'text',
            'body' => 'Oi amor',
        ])->assertSuccessful();

        $chat['conversation']->refresh();
        $this->assertNotNull($chat['conversation']->last_human_admin_at);
        $this->assertNull($chat['conversation']->ai_pending_message_id);
        $this->assertFalse((bool) Message::query()->latest('id')->first()?->sent_by_ai);
    }

    public function test_job_publishes_grok_reply_as_admin(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Oi amor, senti sua falta']],
                ],
            ], 200),
        ]);

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation'], ['reply_delay_minutes' => 0]);

        $this->postJson("/api/chat/conversations/{$chat['conversation']->id}/messages", [
            'type' => 'text',
            'body' => 'Oi Beca',
        ])->assertSuccessful();

        $trigger = Message::query()->where('user_id', $chat['subscriber']->id)->latest('id')->first();
        $this->assertNotNull($trigger);

        $job = new GenerateAiChatReply($chat['conversation']->id, $trigger->id);
        $job->handle(app(AiChatService::class), app(\App\Services\GrokChatClient::class));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $chat['conversation']->id,
            'user_id' => $chat['admin']->id,
            'sent_by_ai' => true,
            'body' => 'Oi amor, senti sua falta',
        ]);
    }

    public function test_job_skips_when_admin_already_replied(): void
    {
        Queue::fake();
        Http::fake();

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation'], ['reply_delay_minutes' => 0]);

        $this->postJson("/api/chat/conversations/{$chat['conversation']->id}/messages", [
            'type' => 'text',
            'body' => 'Oi Beca',
        ])->assertSuccessful();

        $trigger = Message::query()->where('user_id', $chat['subscriber']->id)->latest('id')->first();

        Sanctum::actingAs($chat['admin']);
        $this->postJson("/api/chat/conversations/{$chat['conversation']->id}/messages", [
            'type' => 'text',
            'body' => 'Já te respondi',
        ])->assertSuccessful();

        $job = new GenerateAiChatReply($chat['conversation']->id, $trigger->id);
        $job->handle(app(AiChatService::class), app(\App\Services\GrokChatClient::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $chat['conversation']->id,
            'sent_by_ai' => true,
        ]);
    }

    public function test_reply_ready_at_uses_last_subscriber_message_and_takeover(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-17 20:00:00');

        $chat = $this->openChat();
        $settings = $this->enableAi($chat['admin'], $chat['conversation'], [
            'reply_delay_minutes' => 5,
            'takeover_minutes' => 15,
        ]);

        $trigger = Message::query()->create([
            'conversation_id' => $chat['conversation']->id,
            'user_id' => $chat['subscriber']->id,
            'type' => 'text',
            'body' => 'Oi',
            'delivered_at' => now(),
        ]);

        $chat['conversation']->forceFill([
            'last_human_admin_at' => now(),
        ])->save();

        $readyAt = app(AiChatService::class)->replyReadyAt(
            $chat['conversation']->fresh(),
            $settings->fresh(),
            $trigger->fresh()
        );

        $this->assertSame('2026-08-17 20:15:00', $readyAt->toDateTimeString());
        Carbon::setTestNow();
    }

    public function test_admin_can_save_ai_settings_and_toggle_conversation(): void
    {
        $chat = $this->openChat();
        Sanctum::actingAs($chat['admin']);

        $this->getJson('/api/admin/ai-chat')
            ->assertSuccessful()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.scope', 'selected');

        $this->putJson('/api/admin/ai-chat', [
            'enabled' => true,
            'scope' => 'selected',
            'system_prompt' => 'Fala safada e meiga',
            'reply_delay_minutes' => 5,
            'takeover_minutes' => 15,
        ])->assertSuccessful()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.system_prompt', 'Fala safada e meiga');

        $this->postJson("/api/admin/ai-chat/conversations/{$chat['conversation']->id}/toggle", [
            'ai_enabled' => true,
        ])->assertSuccessful()
            ->assertJsonPath('data.ai_enabled', true);

        $this->assertTrue((bool) $chat['conversation']->fresh()->ai_enabled);
    }

    public function test_subscriber_cannot_access_ai_settings(): void
    {
        $chat = $this->openChat();
        Sanctum::actingAs($chat['subscriber']);

        $this->getJson('/api/admin/ai-chat')->assertForbidden();
    }
}

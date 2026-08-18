<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiChatReply;
use App\Mail\GrokLowBalanceMail;
use App\Models\AiChatSetting;
use App\Models\Assinatura;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AiChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
            'quiet_hours_enabled' => false,
            'quiet_hours_start' => '02:00',
            'quiet_hours_end' => '11:00',
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

    public function test_first_aggression_warning_is_sent_and_token_is_stripped(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => "Amor, não gostei desse jeito. [AGRESSAO_ADVERTIDA]"]],
                ],
            ], 200),
        ]);

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation'], ['reply_delay_minutes' => 0]);

        $this->postJson("/api/chat/conversations/{$chat['conversation']->id}/messages", [
            'type' => 'text',
            'body' => 'Vai se fuder sua merda',
        ])->assertSuccessful();

        $trigger = Message::query()->where('user_id', $chat['subscriber']->id)->latest('id')->first();
        $job = new GenerateAiChatReply($chat['conversation']->id, $trigger->id);
        $job->handle(app(AiChatService::class), app(\App\Services\GrokChatClient::class));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $chat['conversation']->id,
            'sent_by_ai' => true,
            'body' => 'Amor, não gostei desse jeito.',
        ]);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $chat['conversation']->id,
            'body' => 'Amor, não gostei desse jeito. [AGRESSAO_ADVERTIDA]',
        ]);

        $conversation = $chat['conversation']->fresh();
        $this->assertNotNull($conversation->ai_aggression_warned_at);
        $this->assertNull($conversation->ai_blocked_at);
        $this->assertTrue((bool) $conversation->ai_enabled);
    }

    public function test_repeated_aggression_blocks_conversation_without_sending_message(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => '[ENCERRAR_CONVERSA]']],
                ],
            ], 200),
        ]);

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation'], ['reply_delay_minutes' => 0]);
        $chat['conversation']->forceFill([
            'ai_aggression_warned_at' => now(),
        ])->save();

        $this->postJson("/api/chat/conversations/{$chat['conversation']->id}/messages", [
            'type' => 'text',
            'body' => 'Te odeio sua lixo',
        ])->assertSuccessful();

        $trigger = Message::query()->where('user_id', $chat['subscriber']->id)->latest('id')->first();
        $job = new GenerateAiChatReply($chat['conversation']->id, $trigger->id);
        $job->handle(app(AiChatService::class), app(\App\Services\GrokChatClient::class));

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $chat['conversation']->id,
            'sent_by_ai' => true,
        ]);
        $this->assertDatabaseMissing('messages', [
            'body' => '[ENCERRAR_CONVERSA]',
        ]);

        $conversation = $chat['conversation']->fresh();
        $this->assertNotNull($conversation->ai_blocked_at);
        $this->assertSame('agressividade_recorrente', $conversation->ai_blocked_reason);
        $this->assertFalse((bool) $conversation->ai_enabled);
        $this->assertNull($conversation->ai_pending_message_id);
    }

    public function test_blocked_conversation_does_not_dispatch_even_in_all_scope(): void
    {
        Queue::fake();
        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation'], ['scope' => 'all']);
        $chat['conversation']->forceFill([
            'ai_blocked_at' => now(),
            'ai_blocked_reason' => 'agressividade_recorrente',
            'ai_enabled' => false,
        ])->save();

        $this->postJson("/api/chat/conversations/{$chat['conversation']->id}/messages", [
            'type' => 'text',
            'body' => 'Oi de novo',
        ])->assertSuccessful();

        Queue::assertNotPushed(GenerateAiChatReply::class);
    }

    public function test_admin_can_reactivate_blocked_conversation(): void
    {
        $chat = $this->openChat();
        Sanctum::actingAs($chat['admin']);

        $chat['conversation']->forceFill([
            'ai_enabled' => false,
            'ai_blocked_at' => now(),
            'ai_blocked_reason' => 'agressividade_recorrente',
            'ai_aggression_warned_at' => now(),
        ])->save();

        $this->postJson("/api/admin/ai-chat/conversations/{$chat['conversation']->id}/toggle", [
            'ai_enabled' => true,
        ])->assertSuccessful()
            ->assertJsonPath('data.ai_enabled', true)
            ->assertJsonPath('data.ai_blocked', false);

        $conversation = $chat['conversation']->fresh();
        $this->assertTrue((bool) $conversation->ai_enabled);
        $this->assertNull($conversation->ai_blocked_at);
        $this->assertNull($conversation->ai_blocked_reason);
        $this->assertNull($conversation->ai_aggression_warned_at);
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

    public function test_quiet_hours_defer_reply_until_window_ends(): void
    {
        Carbon::setTestNow('2026-08-17 08:00:00');

        $chat = $this->openChat();
        $settings = $this->enableAi($chat['admin'], $chat['conversation'], [
            'reply_delay_minutes' => 0,
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '02:00',
            'quiet_hours_end' => '11:00',
        ]);

        $trigger = Message::query()->create([
            'conversation_id' => $chat['conversation']->id,
            'user_id' => $chat['subscriber']->id,
            'type' => 'text',
            'body' => 'Oi',
            'delivered_at' => now(),
        ]);

        $readyAt = app(AiChatService::class)->replyReadyAt(
            $chat['conversation']->fresh(),
            $settings->fresh(),
            $trigger->fresh()
        );

        $this->assertSame('2026-08-17 14:00:00', $readyAt->toDateTimeString());
        Carbon::setTestNow();
    }

    public function test_quiet_hours_do_not_delay_outside_window(): void
    {
        Carbon::setTestNow('2026-08-17 16:00:00');

        $chat = $this->openChat();
        $settings = $this->enableAi($chat['admin'], $chat['conversation'], [
            'reply_delay_minutes' => 5,
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '02:00',
            'quiet_hours_end' => '11:00',
        ]);

        $trigger = Message::query()->create([
            'conversation_id' => $chat['conversation']->id,
            'user_id' => $chat['subscriber']->id,
            'type' => 'text',
            'body' => 'Oi',
            'delivered_at' => now(),
        ]);

        $readyAt = app(AiChatService::class)->replyReadyAt(
            $chat['conversation']->fresh(),
            $settings->fresh(),
            $trigger->fresh()
        );

        $this->assertSame('2026-08-17 16:05:00', $readyAt->toDateTimeString());
        Carbon::setTestNow();
    }

    public function test_quiet_hours_wrap_around_midnight(): void
    {
        Carbon::setTestNow('2026-08-18 02:00:00');

        $chat = $this->openChat();
        $settings = $this->enableAi($chat['admin'], $chat['conversation'], [
            'reply_delay_minutes' => 0,
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '08:00',
        ]);

        $trigger = Message::query()->create([
            'conversation_id' => $chat['conversation']->id,
            'user_id' => $chat['subscriber']->id,
            'type' => 'text',
            'body' => 'Oi',
            'delivered_at' => now(),
        ]);

        $readyAt = app(AiChatService::class)->replyReadyAt(
            $chat['conversation']->fresh(),
            $settings->fresh(),
            $trigger->fresh()
        );

        $this->assertSame('2026-08-18 11:00:00', $readyAt->toDateTimeString());
        Carbon::setTestNow();
    }

    public function test_job_does_not_publish_during_quiet_hours(): void
    {
        Queue::fake();
        Http::fake();
        Carbon::setTestNow('2026-08-17 08:00:00');

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation'], [
            'reply_delay_minutes' => 0,
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '02:00',
            'quiet_hours_end' => '11:00',
        ]);

        $this->postJson("/api/chat/conversations/{$chat['conversation']->id}/messages", [
            'type' => 'text',
            'body' => 'Oi Beca',
        ])->assertSuccessful();

        $trigger = Message::query()->where('user_id', $chat['subscriber']->id)->latest('id')->first();
        $job = new GenerateAiChatReply($chat['conversation']->id, $trigger->id);
        $job->handle(app(AiChatService::class), app(\App\Services\GrokChatClient::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $chat['conversation']->id,
            'sent_by_ai' => true,
        ]);
        Carbon::setTestNow();
    }

    public function test_admin_can_save_ai_settings_and_toggle_conversation(): void
    {
        $chat = $this->openChat();
        Sanctum::actingAs($chat['admin']);

        $this->getJson('/api/admin/ai-chat')
            ->assertSuccessful()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.scope', 'selected')
            ->assertJsonPath('data.quiet_hours_enabled', true)
            ->assertJsonPath('data.quiet_hours_start', '02:00')
            ->assertJsonPath('data.quiet_hours_end', '11:00');

        $this->putJson('/api/admin/ai-chat', [
            'enabled' => true,
            'scope' => 'selected',
            'system_prompt' => 'Fala safada e meiga',
            'reply_delay_minutes' => 5,
            'takeover_minutes' => 15,
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '02:00',
            'quiet_hours_end' => '11:00',
        ])->assertSuccessful()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.system_prompt', 'Fala safada e meiga')
            ->assertJsonPath('data.quiet_hours_enabled', true)
            ->assertJsonPath('data.quiet_hours_start', '02:00')
            ->assertJsonPath('data.quiet_hours_end', '11:00');

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

    public function test_admin_sees_grok_prepaid_balance(): void
    {
        Http::fake([
            'https://management-api.x.ai/v1/billing/teams/*' => Http::response([
                'total' => ['val' => '-412'],
            ], 200),
        ]);

        config([
            'xai.management_key' => 'test-management-key',
            'xai.team_id' => 'team-test',
        ]);

        $chat = $this->openChat();
        Sanctum::actingAs($chat['admin']);

        $this->getJson('/api/admin/ai-chat')
            ->assertSuccessful()
            ->assertJsonPath('data.credits.configured', true)
            ->assertJsonPath('data.credits.remaining_usd', 4.12)
            ->assertJsonPath('data.credits.error', null);
    }

    public function test_low_balance_sends_alert_email_once(): void
    {
        Mail::fake();
        Cache::flush();
        Http::fake([
            'https://management-api.x.ai/v1/billing/teams/*' => Http::response([
                'total' => ['val' => '-50'],
            ], 200),
        ]);

        config([
            'xai.management_key' => 'test-management-key',
            'xai.team_id' => 'team-test',
        ]);

        $this->artisan('xai:check-low-balance')->assertSuccessful();

        Mail::assertSent(GrokLowBalanceMail::class, function (GrokLowBalanceMail $mail): bool {
            return $mail->hasTo('rehantunes07@gmail.com')
                && $mail->hasTo('rehantunes6@gmail.com')
                && $mail->hasTo('lucaswsb52@gmail.com');
        });

        $this->artisan('xai:check-low-balance')->assertSuccessful();

        Mail::assertSent(GrokLowBalanceMail::class, 1);
    }

    public function test_low_balance_alert_resets_after_top_up(): void
    {
        Mail::fake();
        Cache::flush();
        config([
            'xai.management_key' => 'test-management-key',
            'xai.team_id' => 'team-test',
        ]);

        $cents = '-40';
        Http::fake(function () use (&$cents) {
            return Http::response([
                'total' => ['val' => $cents],
            ], 200);
        });

        $this->artisan('xai:check-low-balance')->assertSuccessful();
        Mail::assertSent(GrokLowBalanceMail::class, 1);

        $cents = '-500';
        $this->artisan('xai:check-low-balance')->assertSuccessful();

        $cents = '-20';
        $this->artisan('xai:check-low-balance')->assertSuccessful();

        Mail::assertSent(GrokLowBalanceMail::class, 2);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiChatReply;
use App\Jobs\PublishAiChatChunk;
use App\Models\AiChatSetting;
use App\Models\Assinatura;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppInstance;
use App\Services\AiChatService;
use App\Services\GrokChatClient;
use App\Services\HumanHandoffNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiChatHandoffAndChunksTest extends TestCase
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
            'reply_delay_minutes' => 0,
            'takeover_minutes' => 15,
            'quiet_hours_enabled' => false,
            'quiet_hours_start' => '02:00',
            'quiet_hours_end' => '11:00',
        ], $overrides));

        $conversation->forceFill(['ai_enabled' => true])->save();

        return $settings;
    }

    private function runGenerateJob(Conversation $conversation, Message $trigger): void
    {
        $job = new GenerateAiChatReply($conversation->id, $trigger->id);
        $job->handle(
            app(AiChatService::class),
            app(GrokChatClient::class),
            app(HumanHandoffNotifier::class),
        );
    }

    private function runChunkJob(PublishAiChatChunk $job): void
    {
        $job->handle(app(AiChatService::class), app(GrokChatClient::class));
    }

    private function pulledChunkJob(): ?PublishAiChatChunk
    {
        $found = null;

        Queue::assertPushed(PublishAiChatChunk::class, function (PublishAiChatChunk $job) use (&$found) {
            $found = $job;

            return true;
        });

        return $found;
    }

    private function fakeGrok(string $content): void
    {
        Http::fake([
            'https://api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => $content]],
                ],
            ], 200),
        ]);
    }

    private function sendSubscriberMessage(Conversation $conversation, string $body): Message
    {
        $this->postJson("/api/chat/conversations/{$conversation->id}/messages", [
            'type' => 'text',
            'body' => $body,
        ])->assertSuccessful();

        return Message::query()->where('user_id', $conversation->subscriber_id)->latest('id')->firstOrFail();
    }

    public function test_simple_personalized_question_does_not_handoff_by_backend_rule(): void
    {
        Queue::fake();
        $this->fakeGrok('Faço sim amor, me conta o que você imagina 💕');

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation']);

        $trigger = $this->sendSubscriberMessage($chat['conversation'], 'Você faz vídeo personalizado?');
        $this->runGenerateJob($chat['conversation'], $trigger);

        $this->assertNull($chat['conversation']->fresh()->ai_human_handoff_at);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $chat['conversation']->id,
            'sent_by_ai' => true,
            'body' => 'Faço sim amor, me conta o que você imagina 💕',
        ]);
        Queue::assertNotPushed(PublishAiChatChunk::class);
    }

    public function test_handoff_tag_pauses_ai_and_never_reaches_the_client(): void
    {
        Queue::fake();
        $this->fakeGrok('Posso ver com a Beca. [ATENDIMENTO_HUMANO_PERSONALIZADO]');

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation']);

        $trigger = $this->sendSubscriberMessage($chat['conversation'], 'Quanto custa o vídeo personalizado?');
        $this->runGenerateJob($chat['conversation'], $trigger);

        $conversation = $chat['conversation']->fresh();
        $this->assertNotNull($conversation->ai_human_handoff_at);
        $this->assertNull($conversation->ai_blocked_at);
        $this->assertTrue((bool) $conversation->ai_enabled);
        $this->assertNull($conversation->ai_pending_message_id);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'sent_by_ai' => true,
        ]);
        $this->assertDatabaseMissing('messages', [
            'body' => 'Posso ver com a Beca. [ATENDIMENTO_HUMANO_PERSONALIZADO]',
        ]);
        $this->assertDatabaseMissing('messages', [
            'body' => 'Posso ver com a Beca.',
        ]);
    }

    public function test_messages_while_waiting_for_beca_do_not_get_ai_replies(): void
    {
        Queue::fake();
        $this->fakeGrok('[ATENDIMENTO_HUMANO_PERSONALIZADO]');

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation']);

        $trigger = $this->sendSubscriberMessage($chat['conversation'], 'Qual o prazo?');
        $this->runGenerateJob($chat['conversation'], $trigger);

        Queue::fake();
        $this->sendSubscriberMessage($chat['conversation']->fresh(), 'E o Pix?');

        Queue::assertNotPushed(GenerateAiChatReply::class);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $chat['conversation']->id,
            'sent_by_ai' => true,
        ]);
    }

    public function test_handoff_notifies_whatsapp_only_once(): void
    {
        Queue::fake();
        config([
            'evolution.base_url' => 'https://evolution.test',
            'evolution.api_key' => 'evo-key',
        ]);

        Http::fake([
            'https://api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => '[ATENDIMENTO_HUMANO_PERSONALIZADO]']],
                ],
            ], 200),
            'https://evolution.test/*' => Http::response(['ok' => true], 200),
        ]);

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation']);

        WhatsAppInstance::query()->create([
            'admin_id' => $chat['admin']->id,
            'nome_instancia' => 'rebeca_test',
            'status' => WhatsAppInstance::STATUS_CONECTADO,
            'numero' => '5511999999999',
            'notify_number' => '11988887777',
        ]);

        $trigger = $this->sendSubscriberMessage($chat['conversation'], 'Quanto fica?');
        $this->runGenerateJob($chat['conversation'], $trigger);

        $this->assertNotNull($chat['conversation']->fresh()->ai_human_handoff_notified_at);
        $this->assertSame(1, $this->evolutionTextCount());

        Queue::fake();
        $this->sendSubscriberMessage($chat['conversation']->fresh(), 'E o prazo?');
        Queue::assertNotPushed(GenerateAiChatReply::class);
        $this->assertSame(1, $this->evolutionTextCount());
    }

    public function test_beca_manual_reply_clears_handoff_and_follows_takeover(): void
    {
        Queue::fake();
        $this->fakeGrok('[ATENDIMENTO_HUMANO_PERSONALIZADO]');

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation'], ['takeover_minutes' => 15]);

        $trigger = $this->sendSubscriberMessage($chat['conversation'], 'Qual o valor?');
        $this->runGenerateJob($chat['conversation'], $trigger);
        $this->assertNotNull($chat['conversation']->fresh()->ai_human_handoff_at);

        Sanctum::actingAs($chat['admin']);
        $this->postJson("/api/chat/conversations/{$chat['conversation']->id}/messages", [
            'type' => 'text',
            'body' => 'Amor, o vídeo fica 250',
        ])->assertSuccessful();

        $conversation = $chat['conversation']->fresh();
        $this->assertNull($conversation->ai_human_handoff_at);
        $this->assertNull($conversation->ai_human_handoff_notified_at);
        $this->assertNotNull($conversation->last_human_admin_at);

        Sanctum::actingAs($chat['subscriber']);
        Queue::fake();
        $this->sendSubscriberMessage($conversation, 'Fechado');
        Queue::assertPushed(GenerateAiChatReply::class);
    }

    public function test_single_chunk_reply_still_works(): void
    {
        Queue::fake();
        $this->fakeGrok('Oi amor, tudo bem?');

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation']);

        $trigger = $this->sendSubscriberMessage($chat['conversation'], 'Oi');
        $this->runGenerateJob($chat['conversation'], $trigger);

        $this->assertSame(1, Message::query()->where('sent_by_ai', true)->count());
        Queue::assertNotPushed(PublishAiChatChunk::class);
    }

    public function test_two_and_three_chunks_are_published_separately(): void
    {
        Queue::fake();
        $this->fakeGrok("Oiii bb\n\nmimiu bem?\n\ntô pensando em você");

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation']);

        $trigger = $this->sendSubscriberMessage($chat['conversation'], 'Oi');
        $this->runGenerateJob($chat['conversation'], $trigger);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $chat['conversation']->id,
            'sent_by_ai' => true,
            'body' => 'Oiii bb',
        ]);
        $this->assertSame(1, Message::query()->where('sent_by_ai', true)->count());

        $firstChunk = $this->pulledChunkJob();
        $this->assertNotNull($firstChunk);
        $this->runChunkJob($firstChunk);

        $this->assertDatabaseHas('messages', [
            'body' => 'mimiu bem?',
            'sent_by_ai' => true,
        ]);
        $this->assertSame(2, Message::query()->where('sent_by_ai', true)->count());

        $jobs = [];
        Queue::assertPushed(PublishAiChatChunk::class, function (PublishAiChatChunk $job) use (&$jobs) {
            $jobs[] = $job;

            return true;
        });
        $this->assertCount(2, $jobs);
        $this->runChunkJob($jobs[1]);

        $this->assertDatabaseHas('messages', [
            'body' => 'tô pensando em você',
            'sent_by_ai' => true,
        ]);
        $this->assertSame(3, Message::query()->where('sent_by_ai', true)->count());
    }

    public function test_client_reply_between_chunks_cancels_the_rest(): void
    {
        Queue::fake();
        $this->fakeGrok("Mensagem A\n\nMensagem B");

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation']);

        $trigger = $this->sendSubscriberMessage($chat['conversation'], 'Oi');
        $this->runGenerateJob($chat['conversation'], $trigger);

        $chunk = $this->pulledChunkJob();
        $this->assertNotNull($chunk);

        Sanctum::actingAs($chat['subscriber']);
        $this->sendSubscriberMessage($chat['conversation']->fresh(), 'E aí?');

        $this->runChunkJob($chunk);

        $this->assertDatabaseMissing('messages', [
            'body' => 'Mensagem B',
        ]);
        $this->assertSame(1, Message::query()->where('sent_by_ai', true)->count());
    }

    public function test_manual_beca_reply_between_chunks_cancels_the_rest(): void
    {
        Queue::fake();
        $this->fakeGrok("Mensagem A\n\nMensagem B");

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation']);

        $trigger = $this->sendSubscriberMessage($chat['conversation'], 'Oi');
        $this->runGenerateJob($chat['conversation'], $trigger);

        $chunk = $this->pulledChunkJob();
        $this->assertNotNull($chunk);

        Sanctum::actingAs($chat['admin']);
        $this->postJson("/api/chat/conversations/{$chat['conversation']->id}/messages", [
            'type' => 'text',
            'body' => 'Já te respondi',
        ])->assertSuccessful();

        $this->runChunkJob($chunk);

        $this->assertDatabaseMissing('messages', [
            'body' => 'Mensagem B',
        ]);
    }

    public function test_stale_chunk_job_does_not_publish(): void
    {
        Queue::fake();
        $this->fakeGrok("Mensagem A\n\nMensagem B");

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation']);

        $trigger = $this->sendSubscriberMessage($chat['conversation'], 'Oi');
        $this->runGenerateJob($chat['conversation'], $trigger);

        $chunk = $this->pulledChunkJob();
        $this->assertNotNull($chunk);

        Message::query()->create([
            'conversation_id' => $chat['conversation']->id,
            'user_id' => $chat['admin']->id,
            'type' => 'text',
            'body' => 'Outra resposta da IA',
            'sent_by_ai' => true,
            'delivered_at' => now(),
        ]);

        $this->runChunkJob($chunk);

        $this->assertDatabaseMissing('messages', [
            'body' => 'Mensagem B',
        ]);
    }

    public function test_evolution_failure_does_not_break_handoff(): void
    {
        Queue::fake();
        config([
            'evolution.base_url' => 'https://evolution.test',
            'evolution.api_key' => 'evo-key',
        ]);

        Http::fake([
            'https://api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => '[ATENDIMENTO_HUMANO_PERSONALIZADO]']],
                ],
            ], 200),
            'https://evolution.test/*' => Http::response(['error' => 'down'], 500),
        ]);

        $chat = $this->openChat();
        $this->enableAi($chat['admin'], $chat['conversation']);

        WhatsAppInstance::query()->create([
            'admin_id' => $chat['admin']->id,
            'nome_instancia' => 'rebeca_test',
            'status' => WhatsAppInstance::STATUS_CONECTADO,
            'notify_number' => '11988887777',
        ]);

        $trigger = $this->sendSubscriberMessage($chat['conversation'], 'Quanto custa?');
        $this->runGenerateJob($chat['conversation'], $trigger);

        $conversation = $chat['conversation']->fresh();
        $this->assertNotNull($conversation->ai_human_handoff_at);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'sent_by_ai' => true,
        ]);
    }

    private function evolutionTextCount(): int
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'sendText'))
            ->count();
    }
}

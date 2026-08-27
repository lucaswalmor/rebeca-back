<?php

namespace Tests\Feature;

use App\Models\Assinatura;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatFeatureTest extends TestCase
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
            'chat_media_credits' => 0,
        ], $overrides));
    }

    private function createActiveSubscription(User $user): Assinatura
    {
        return Assinatura::create([
            'user_id' => $user->id,
            'data_inicio' => now()->subDay(),
            'data_fim' => now()->addMonth(),
            'tipo_assinatura' => 'mensal',
            'status' => 'aprovado',
            'valor' => 10,
            'plano' => '1_mes',
        ]);
    }

    public function test_subscriber_without_subscription_cannot_open_chat(): void
    {
        $this->createUser([
            'is_admin' => true,
            'email' => 'admin@example.com',
            'apelido' => 'admin',
        ]);
        $subscriber = $this->createUser();

        Sanctum::actingAs($subscriber);

        $this->postJson('/api/chat/conversations/open')
            ->assertForbidden()
            ->assertJsonPath('requires_subscription', true);
    }

    public function test_subscriber_with_subscription_can_open_and_send_text(): void
    {
        $admin = $this->createUser([
            'is_admin' => true,
            'email' => 'admin@example.com',
            'apelido' => 'admin',
        ]);
        $subscriber = $this->createUser();
        $this->createActiveSubscription($subscriber);

        Sanctum::actingAs($subscriber);

        $open = $this->postJson('/api/chat/conversations/open')->assertSuccessful();
        $conversationId = $open->json('data.id') ?? $open->json('id');

        $this->assertNotNull($conversationId);
        $this->assertDatabaseHas('conversations', [
            'id' => $conversationId,
            'admin_id' => $admin->id,
            'subscriber_id' => $subscriber->id,
        ]);

        $this->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'type' => 'text',
            'body' => 'Olá Rebeca',
        ])->assertSuccessful()
            ->assertJsonPath('data.body', 'Olá Rebeca');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'user_id' => $subscriber->id,
            'body' => 'Olá Rebeca',
        ]);
    }

    public function test_subscriber_cannot_send_media_without_credits(): void
    {
        $this->createUser([
            'is_admin' => true,
            'email' => 'admin@example.com',
            'apelido' => 'admin',
        ]);
        $subscriber = $this->createUser(['chat_media_credits' => 0]);
        $this->createActiveSubscription($subscriber);

        Sanctum::actingAs($subscriber);

        $conversationId = $this->postJson('/api/chat/conversations/open')->json('data.id');

        $this->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'type' => 'image',
            'body' => null,
        ])->assertStatus(422);
    }

    public function test_users_can_like_reply_edit_and_delete_messages(): void
    {
        $admin = $this->createUser([
            'is_admin' => true,
            'email' => 'admin@example.com',
            'apelido' => 'admin',
        ]);
        $subscriber = $this->createUser();
        $this->createActiveSubscription($subscriber);

        $conversation = Conversation::create([
            'admin_id' => $admin->id,
            'subscriber_id' => $subscriber->id,
            'last_message_at' => now(),
        ]);

        Sanctum::actingAs($subscriber);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $subscriber->id,
            'type' => 'text',
            'body' => 'Mensagem original',
            'delivered_at' => now(),
        ]);

        $this->postJson("/api/chat/messages/{$message->id}/like")
            ->assertOk()
            ->assertJsonPath('data.liked_by_me', true);

        $this->putJson("/api/chat/messages/{$message->id}", [
            'body' => 'Mensagem editada',
        ])->assertOk()
            ->assertJsonPath('data.body', 'Mensagem editada');

        $this->deleteJson("/api/chat/messages/{$message->id}")
            ->assertOk();

        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }

    public function test_admin_sees_conversations_list(): void
    {
        $admin = $this->createUser([
            'is_admin' => true,
            'email' => 'admin@example.com',
            'apelido' => 'admin',
        ]);
        $subscriber = $this->createUser();
        $this->createActiveSubscription($subscriber);

        Conversation::create([
            'admin_id' => $admin->id,
            'subscriber_id' => $subscriber->id,
            'last_message_at' => now(),
        ]);

        $conversation = Conversation::query()
            ->where('admin_id', $admin->id)
            ->where('subscriber_id', $subscriber->id)
            ->firstOrFail();

        Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $subscriber->id,
            'type' => 'text',
            'body' => 'Oi, última mensagem do chat',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.latest_message.body', 'Oi, última mensagem do chat')
            ->assertJsonPath('data.0.latest_message.type', 'text');
    }

    public function test_subscriber_cannot_export_conversations(): void
    {
        $this->createUser([
            'is_admin' => true,
            'email' => 'admin@example.com',
            'apelido' => 'admin',
        ]);
        $subscriber = $this->createUser();
        $this->createActiveSubscription($subscriber);

        Sanctum::actingAs($subscriber);

        $this->getJson('/api/chat/conversations/export')
            ->assertForbidden();
    }

    public function test_admin_can_export_conversations_with_client_and_admin_turns(): void
    {
        $admin = $this->createUser([
            'is_admin' => true,
            'email' => 'admin@example.com',
            'apelido' => 'admin',
        ]);
        $subscriber = $this->createUser([
            'nome' => 'Joao',
            'sobrenome' => 'Silva',
            'apelido' => 'joaosilva',
        ]);
        $this->createActiveSubscription($subscriber);

        $conversation = Conversation::create([
            'admin_id' => $admin->id,
            'subscriber_id' => $subscriber->id,
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $admin->id,
            'type' => 'text',
            'body' => 'Oiii bb, tudo bem?',
            'sent_by_ai' => false,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $subscriber->id,
            'type' => 'text',
            'body' => 'Oi, quero te conhecer',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $admin->id,
            'type' => 'text',
            'body' => 'Claro amor, me conta',
            'sent_by_ai' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/chat/conversations/export')
            ->assertOk()
            ->assertJsonPath('data.conversations_count', 1)
            ->assertJsonPath('data.messages_count', 3)
            ->assertJsonPath('data.conversations.0.cliente.apelido', 'joaosilva')
            ->assertJsonPath('data.conversations.0.messages.0.from', 'admin')
            ->assertJsonPath('data.conversations.0.messages.0.via_ia', false)
            ->assertJsonPath('data.conversations.0.messages.0.text', 'Oiii bb, tudo bem?')
            ->assertJsonPath('data.conversations.0.messages.1.from', 'cliente')
            ->assertJsonPath('data.conversations.0.messages.1.text', 'Oi, quero te conhecer')
            ->assertJsonPath('data.conversations.0.messages.2.from', 'admin')
            ->assertJsonPath('data.conversations.0.messages.2.via_ia', true)
            ->assertJsonPath('data.conversations.0.messages.2.text', 'Claro amor, me conta');
    }

    public function test_subscriber_can_clear_chat_only_for_themselves(): void
    {
        $admin = $this->createUser([
            'is_admin' => true,
            'email' => 'admin@example.com',
            'apelido' => 'admin',
        ]);
        $subscriber = $this->createUser();
        $this->createActiveSubscription($subscriber);

        $conversation = Conversation::create([
            'admin_id' => $admin->id,
            'subscriber_id' => $subscriber->id,
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $admin->id,
            'type' => 'text',
            'body' => 'Olá',
        ]);

        Sanctum::actingAs($subscriber);

        $this->postJson("/api/chat/conversations/{$conversation->id}/clear", ['scope' => 'me'])
            ->assertOk()
            ->assertJsonPath('scope', 'me');

        $this->assertNotNull($conversation->fresh()->subscriber_cleared_at);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'body' => 'Olá',
        ]);

        $this->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson("/api/chat/conversations/{$conversation->id}/clear", ['scope' => 'everyone'])
            ->assertForbidden();
    }

    public function test_admin_can_clear_for_everyone(): void
    {
        $admin = $this->createUser([
            'is_admin' => true,
            'email' => 'admin@example.com',
            'apelido' => 'admin',
        ]);
        $subscriber = $this->createUser();
        $this->createActiveSubscription($subscriber);

        $conversation = Conversation::create([
            'admin_id' => $admin->id,
            'subscriber_id' => $subscriber->id,
            'last_message_at' => now(),
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $subscriber->id,
            'type' => 'text',
            'body' => 'Mensagem antiga',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/chat/conversations/{$conversation->id}/clear", ['scope' => 'everyone'])
            ->assertOk()
            ->assertJsonPath('scope', 'everyone');

        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
        $this->assertNull($conversation->fresh()->last_message_at);
    }

    public function test_admin_can_clear_only_for_herself(): void
    {
        $admin = $this->createUser([
            'is_admin' => true,
            'email' => 'admin@example.com',
            'apelido' => 'admin',
        ]);
        $subscriber = $this->createUser();
        $this->createActiveSubscription($subscriber);

        $conversation = Conversation::create([
            'admin_id' => $admin->id,
            'subscriber_id' => $subscriber->id,
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $subscriber->id,
            'type' => 'text',
            'body' => 'Oi Rebeca',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/chat/conversations/{$conversation->id}/clear", ['scope' => 'me'])
            ->assertOk();

        $this->assertNotNull($conversation->fresh()->admin_cleared_at);

        $this->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Sanctum::actingAs($subscriber);

        $this->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}

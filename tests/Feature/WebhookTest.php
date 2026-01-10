<?php

namespace Tests\Feature;

use App\Models\Assinatura;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Testar processamento de webhook da InfinitePay
     */
    public function test_webhook_infinitepay_success(): void
    {
        // Criar um usuário de teste
        $user = User::factory()->create();

        // Criar uma assinatura de teste
        $assinatura = Assinatura::create([
            'user_id' => $user->id,
            'data_inicio' => now(),
            'data_fim' => now()->addMonth(),
            'tipo_assinatura' => 'mensal',
            'status' => 'pendente',
            'order_nsu' => 'order-test-123',
            'valor' => 29.90,
            'plano' => '1_mes',
        ]);

        // Dados do webhook simulando resposta da InfinitePay
        $webhookData = [
            'invoice_slug' => 'abc123',
            'amount' => 2990, // em centavos
            'paid_amount' => 2990,
            'installments' => 1,
            'capture_method' => 'pix',
            'transaction_nsu' => 'txn-uuid-123',
            'order_nsu' => 'order-test-123',
            'receipt_url' => 'https://comprovante.com/123',
        ];

        // Fazer requisição POST para o webhook
        $response = $this->postJson('/api/webhooks/infinitepay', $webhookData);

        // Verificar se retornou status 200
        $response->assertStatus(200)
                ->assertJson(['message' => 'Webhook processado com sucesso']);

        // Verificar se a assinatura foi atualizada
        $assinatura->refresh();
        $this->assertEquals('aprovado', $assinatura->status);
        $this->assertEquals('txn-uuid-123', $assinatura->transaction_nsu);
        $this->assertEquals('abc123', $assinatura->invoice_slug);
        $this->assertEquals('https://comprovante.com/123', $assinatura->receipt_url);
        $this->assertEquals(29.90, $assinatura->paid_amount);
        $this->assertEquals(1, $assinatura->installments);
        $this->assertEquals('pix', $assinatura->capture_method);
        $this->assertNotNull($assinatura->payment_date);
    }

    /**
     * Testar webhook com assinatura não encontrada
     */
    public function test_webhook_assinatura_not_found(): void
    {
        $webhookData = [
            'invoice_slug' => 'abc123',
            'amount' => 2990,
            'paid_amount' => 2990,
            'installments' => 1,
            'capture_method' => 'pix',
            'transaction_nsu' => 'txn-uuid-123',
            'order_nsu' => 'order-nao-existe',
        ];

        $response = $this->postJson('/api/webhooks/infinitepay', $webhookData);

        $response->assertStatus(404)
                ->assertJson(['message' => 'Assinatura não encontrada']);
    }

    /**
     * Testar webhook com dados inválidos
     */
    public function test_webhook_invalid_data(): void
    {
        $webhookData = [
            'invoice_slug' => '', // obrigatório mas vazio
            'amount' => 'invalid', // deve ser integer
        ];

        $response = $this->postJson('/api/webhooks/infinitepay', $webhookData);

        $response->assertStatus(400)
                ->assertJsonStructure(['message', 'errors']);
    }

    /**
     * Testar webhook duplicado (já processado)
     */
    public function test_webhook_duplicate_processing(): void
    {
        // Criar um usuário de teste
        $user = User::factory()->create();

        // Criar uma assinatura já aprovada
        $assinatura = Assinatura::create([
            'user_id' => $user->id,
            'data_inicio' => now(),
            'data_fim' => now()->addMonth(),
            'tipo_assinatura' => 'mensal',
            'status' => 'aprovado', // Já aprovado
            'order_nsu' => 'order-test-456',
            'valor' => 29.90,
            'plano' => '1_mes',
        ]);

        $webhookData = [
            'invoice_slug' => 'def456',
            'amount' => 2990,
            'paid_amount' => 2990,
            'installments' => 1,
            'capture_method' => 'pix',
            'transaction_nsu' => 'txn-uuid-456',
            'order_nsu' => 'order-test-456',
        ];

        $response = $this->postJson('/api/webhooks/infinitepay', $webhookData);

        // Deve retornar 200 pois o webhook já foi processado
        $response->assertStatus(200)
                ->assertJson(['message' => 'Webhook já processado']);
    }
}

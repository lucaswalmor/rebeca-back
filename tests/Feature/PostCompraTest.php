<?php

namespace Tests\Feature;

use App\Models\Assinatura;
use App\Models\Post;
use App\Models\PostCompra;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostCompraTest extends TestCase
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
        ], $overrides));
    }

    private function createSubscriberWithPost(): array
    {
        $author = $this->createUser(['apelido' => 'author', 'email' => 'author@example.com']);
        $buyer = $this->createUser(['apelido' => 'buyer', 'email' => 'buyer@example.com']);

        Assinatura::create([
            'user_id' => $buyer->id,
            'data_inicio' => now()->subDay(),
            'data_fim' => now()->addMonth(),
            'tipo_assinatura' => 'mensal',
            'status' => 'aprovado',
            'order_nsu' => 'order-buyer-'.uniqid(),
            'valor' => 29.90,
            'plano' => '1_mes',
        ]);

        $post = Post::create([
            'user_id' => $author->id,
            'tipo_post' => 2,
            'description' => 'Post pago',
            'preco' => 10.00,
            'status' => 'ativo',
            'is_fixed' => false,
        ]);

        return [$buyer, $post];
    }

    public function test_reutiliza_compra_pendente_sem_link_ao_desbloquear_novamente(): void
    {
        [$buyer, $post] = $this->createSubscriberWithPost();

        $compraExistente = PostCompra::create([
            'user_id' => $buyer->id,
            'post_id' => $post->id,
            'status' => 'pendente',
            'valor' => 10.00,
            'link_pagamento' => null,
        ]);

        Http::fake([
            'api.infinitepay.io/*' => Http::response([
                'checkout_url' => 'https://checkout.infinitepay.io/test-link',
            ], 200),
        ]);

        Sanctum::actingAs($buyer);

        $response = $this->postJson("/api/posts/{$post->id}/comprar");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'link' => 'https://checkout.infinitepay.io/test-link',
                'compra_id' => $compraExistente->id,
            ]);

        $this->assertDatabaseCount('post_compras', 1);
        $this->assertDatabaseHas('post_compras', [
            'id' => $compraExistente->id,
            'user_id' => $buyer->id,
            'post_id' => $post->id,
            'status' => 'pendente',
            'link_pagamento' => 'https://checkout.infinitepay.io/test-link',
        ]);
    }

    public function test_reutiliza_link_quando_compra_pendente_ja_tem_link(): void
    {
        [$buyer, $post] = $this->createSubscriberWithPost();

        $compraExistente = PostCompra::create([
            'user_id' => $buyer->id,
            'post_id' => $post->id,
            'status' => 'pendente',
            'valor' => 10.00,
            'order_nsu' => 'post-1-old',
            'link_pagamento' => 'https://checkout.infinitepay.io/existing-link',
        ]);

        Sanctum::actingAs($buyer);

        $response = $this->postJson("/api/posts/{$post->id}/comprar");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'link' => 'https://checkout.infinitepay.io/existing-link',
                'compra_id' => $compraExistente->id,
            ]);

        $this->assertDatabaseCount('post_compras', 1);
        Http::assertNothingSent();
    }
}

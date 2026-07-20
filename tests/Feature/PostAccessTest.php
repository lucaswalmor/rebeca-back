<?php

namespace Tests\Feature;

use App\Models\Assinatura;
use App\Models\Post;
use App\Models\PostCompra;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostAccessTest extends TestCase
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

    private function createPostWithMedia(User $author): Post
    {
        $post = Post::create([
            'user_id' => $author->id,
            'tipo_post' => 2,
            'description' => 'Post de teste',
            'preco' => 19.90,
            'status' => 'ativo',
            'is_fixed' => false,
        ]);

        PostMedia::create([
            'post_id' => $post->id,
            'path' => 'posts/preview.jpg',
            'tipo' => 'image',
            'ordem' => 0,
            'is_preview' => true,
        ]);

        PostMedia::create([
            'post_id' => $post->id,
            'path' => 'posts/exclusivo-1.jpg',
            'tipo' => 'image',
            'ordem' => 1,
            'is_preview' => false,
        ]);

        PostMedia::create([
            'post_id' => $post->id,
            'path' => 'posts/exclusivo-2.jpg',
            'tipo' => 'image',
            'ordem' => 2,
            'is_preview' => false,
        ]);

        return $post;
    }

    public function test_admin_ve_toda_midia_liberada(): void
    {
        $admin = $this->createUser([
            'is_admin' => true,
            'apelido' => 'admin',
            'email' => 'admin@example.com',
        ]);
        $this->createPostWithMedia($admin);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/posts');

        $response->assertOk();
        $post = $response->json('data.0');

        $this->assertTrue($post['has_full_access']);
        $this->assertTrue($post['has_preview_access']);
        $this->assertFalse($post['is_locked']);
        $this->assertCount(3, $post['media']);
    }

    public function test_visitante_nao_ve_previa_nem_exclusivo(): void
    {
        $author = $this->createUser(['apelido' => 'author', 'email' => 'author@example.com']);
        $this->createPostWithMedia($author);

        $response = $this->getJson('/api/posts');

        $response->assertOk();
        $post = $response->json('data.0');

        $this->assertFalse($post['has_full_access']);
        $this->assertFalse($post['has_preview_access']);
        $this->assertTrue($post['is_locked']);
        $this->assertCount(0, $post['media']);
        $this->assertNull($post['preview']);
    }

    public function test_assinante_ve_apenas_previa(): void
    {
        $author = $this->createUser(['apelido' => 'author2', 'email' => 'author2@example.com']);
        $subscriber = $this->createUser(['apelido' => 'sub', 'email' => 'sub@example.com']);
        $this->createPostWithMedia($author);

        Assinatura::create([
            'user_id' => $subscriber->id,
            'data_inicio' => now()->subDay(),
            'data_fim' => now()->addMonth(),
            'tipo_assinatura' => 'mensal',
            'status' => 'aprovado',
            'order_nsu' => 'order-sub-1',
            'valor' => 29.90,
            'plano' => '1_mes',
        ]);

        Sanctum::actingAs($subscriber);

        $response = $this->getJson('/api/posts');

        $response->assertOk();
        $post = $response->json('data.0');

        $this->assertTrue($post['has_preview_access']);
        $this->assertFalse($post['has_full_access']);
        $this->assertTrue($post['is_locked']);
        $this->assertCount(1, $post['media']);
        $this->assertTrue($post['media'][0]['is_preview']);
    }

    public function test_assinante_com_compra_ve_previa_e_conteudo_exclusivo(): void
    {
        $author = $this->createUser(['apelido' => 'author3', 'email' => 'author3@example.com']);
        $buyer = $this->createUser(['apelido' => 'buyer', 'email' => 'buyer@example.com']);
        $postModel = $this->createPostWithMedia($author);

        Assinatura::create([
            'user_id' => $buyer->id,
            'data_inicio' => now()->subDay(),
            'data_fim' => now()->addMonth(),
            'tipo_assinatura' => 'mensal',
            'status' => 'aprovado',
            'order_nsu' => 'order-buyer-1',
            'valor' => 29.90,
            'plano' => '1_mes',
        ]);

        PostCompra::create([
            'user_id' => $buyer->id,
            'post_id' => $postModel->id,
            'order_nsu' => 'post-buyer-1',
            'valor' => 19.90,
            'status' => 'aprovado',
            'payment_date' => now(),
        ]);

        Sanctum::actingAs($buyer);

        $response = $this->getJson('/api/posts');

        $response->assertOk();
        $post = $response->json('data.0');

        $this->assertTrue($post['has_preview_access']);
        $this->assertTrue($post['has_full_access']);
        $this->assertFalse($post['is_locked']);
        $this->assertCount(3, $post['media']);
        $this->assertTrue($post['media'][0]['is_preview']);
        $this->assertFalse($post['media'][1]['is_preview']);
    }
}

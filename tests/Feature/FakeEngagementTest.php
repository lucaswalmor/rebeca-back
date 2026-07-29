<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Services\FakeEngagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FakeEngagementTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::create([
            'nome' => 'Admin',
            'sobrenome' => 'Test',
            'apelido' => 'admin_fake_test',
            'email' => 'admin-fake-test@example.com',
            'password' => bcrypt('password'),
            'telefone' => '11999999999',
            'data_nascimento' => '1990-01-01',
            'is_admin' => true,
        ]);
    }

    public function test_cria_usuarios_fakes_assinantes(): void
    {
        $service = app(FakeEngagementService::class);
        $users = $service->ensureFakeUsers();

        $this->assertCount(50, $users);
        $this->assertDatabaseCount('users', 50);
        $this->assertDatabaseHas('assinaturas', [
            'order_nsu' => 'fake-sub-'.$users->first()->id,
            'status' => 'aprovado',
        ]);
    }

    public function test_post_novo_recebe_curtidas_e_comentarios_fakes(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/posts', [
            'description' => 'Post com engajamento fake',
            'preco' => 0,
        ]);

        $response->assertCreated();
        $postId = $response->json('data.id');

        $this->assertDatabaseHas('posts', ['id' => $postId]);

        $likes = \App\Models\PostLike::where('post_id', $postId)->count();
        $comments = \App\Models\Comment::where('post_id', $postId)->count();

        $this->assertGreaterThanOrEqual(1, $likes);
        $this->assertLessThanOrEqual(5, $likes);
        $this->assertGreaterThanOrEqual(1, $comments);
        $this->assertLessThanOrEqual(5, $comments);

        $likeUserIds = \App\Models\PostLike::where('post_id', $postId)->pluck('user_id');
        $this->assertSame($likeUserIds->count(), $likeUserIds->unique()->count());

        $commentUserIds = \App\Models\Comment::where('post_id', $postId)->pluck('user_id');
        $this->assertSame($commentUserIds->count(), $commentUserIds->unique()->count());

        $fakeApelidos = \App\Models\User::query()
            ->where('email', 'like', '%@'.FakeEngagementService::FAKE_EMAIL_DOMAIN)
            ->pluck('apelido');

        $this->assertFalse($fakeApelidos->contains(fn ($apelido) => str_starts_with((string) $apelido, 'fan_rebeca_')));
    }

    public function test_backfill_aplica_engajamento_em_posts_existentes(): void
    {
        $admin = $this->createAdmin();

        $post = Post::withoutEvents(function () use ($admin) {
            return Post::create([
                'user_id' => $admin->id,
                'tipo_post' => 2,
                'description' => 'Post antigo sem engajamento',
                'preco' => 0,
                'status' => 'ativo',
                'is_fixed' => false,
            ]);
        });

        $this->assertSame(0, \App\Models\PostLike::where('post_id', $post->id)->count());

        $updated = app(FakeEngagementService::class)->seedMissingPosts();

        $this->assertSame(1, $updated);
        $this->assertGreaterThanOrEqual(1, \App\Models\PostLike::where('post_id', $post->id)->count());
        $this->assertGreaterThanOrEqual(1, \App\Models\Comment::where('post_id', $post->id)->count());
    }
}

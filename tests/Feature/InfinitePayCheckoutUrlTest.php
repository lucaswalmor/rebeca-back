<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InfinitePayCheckoutUrlTest extends TestCase
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

    public function test_gerar_link_usa_url_nova_do_checkout_integrado(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        Http::fake([
            'https://api.checkout.infinitepay.io/links' => Http::response([
                'url' => 'https://checkout.infinitepay.com.br/abc123',
            ], 200),
            'https://api.infinitepay.io/*' => Http::response(['message' => 'URL antiga'], 410),
        ]);

        $response = $this->postJson('/api/assinaturas/gerar-link-pagamento', [
            'plano' => '1_mes',
            'valor' => 19.90,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('link', 'https://checkout.infinitepay.com.br/abc123');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.checkout.infinitepay.io/links');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.infinitepay.io/invoices/public/checkout'));
    }
}

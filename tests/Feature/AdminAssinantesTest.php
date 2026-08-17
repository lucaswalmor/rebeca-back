<?php

namespace Tests\Feature;

use App\Models\Assinatura;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAssinantesTest extends TestCase
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

    public function test_nao_admin_nao_lista_assinantes(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/assinantes')
            ->assertForbidden();
    }

    public function test_admin_ve_valor_e_total_gasto_das_assinaturas(): void
    {
        $admin = $this->createUser([
            'is_admin' => true,
            'apelido' => 'admin',
            'email' => 'admin@example.com',
        ]);
        $subscriber = $this->createUser([
            'nome' => 'Marcos',
            'sobrenome' => 'Lopes',
            'apelido' => 'marcos',
            'email' => 'marcos@example.com',
        ]);

        Assinatura::create([
            'user_id' => $subscriber->id,
            'data_inicio' => now()->subMonths(2),
            'data_fim' => now()->subMonth(),
            'tipo_assinatura' => 'mensal',
            'status' => 'aprovado',
            'valor' => 49.90,
            'paid_amount' => 49.90,
        ]);

        Assinatura::create([
            'user_id' => $subscriber->id,
            'data_inicio' => now()->subDay(),
            'data_fim' => now()->addMonth(),
            'tipo_assinatura' => 'mensal',
            'status' => 'aprovado',
            'valor' => 59.90,
            'paid_amount' => 59.90,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/assinantes?status=ativo');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $subscriber->id)
            ->assertJsonPath('data.0.plano', 'mensal')
            ->assertJsonPath('data.0.valor', 59.9)
            ->assertJsonPath('data.0.total_gasto', 109.8);
    }

    public function test_admin_pagina_assinantes_de_dez_em_dez(): void
    {
        $admin = $this->createUser([
            'is_admin' => true,
            'apelido' => 'admin',
            'email' => 'admin@example.com',
        ]);

        for ($i = 1; $i <= 12; $i++) {
            $this->createUser([
                'nome' => "Cliente {$i}",
                'apelido' => "cliente{$i}",
                'email' => "cliente{$i}@example.com",
            ]);
        }

        Sanctum::actingAs($admin);

        $page1 = $this->getJson('/api/admin/assinantes?per_page=10&page=1');
        $page1->assertOk()
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 12);
        $this->assertCount(10, $page1->json('data'));

        $page2 = $this->getJson('/api/admin/assinantes?per_page=10&page=2');
        $page2->assertOk()
            ->assertJsonPath('meta.current_page', 2);
        $this->assertCount(2, $page2->json('data'));
    }

    public function test_usa_paid_amount_quando_valor_esta_vazio(): void
    {
        $admin = $this->createUser([
            'is_admin' => true,
            'apelido' => 'admin',
            'email' => 'admin@example.com',
        ]);
        $subscriber = $this->createUser([
            'email' => 'pago@example.com',
        ]);

        Assinatura::create([
            'user_id' => $subscriber->id,
            'data_inicio' => now()->subDay(),
            'data_fim' => now()->addMonth(),
            'tipo_assinatura' => 'trimestral',
            'status' => 'aprovado',
            'valor' => null,
            'paid_amount' => 120.00,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/assinantes?status=ativo')
            ->assertOk()
            ->assertJsonPath('data.0.valor', 120)
            ->assertJsonPath('data.0.total_gasto', 120);
    }
}

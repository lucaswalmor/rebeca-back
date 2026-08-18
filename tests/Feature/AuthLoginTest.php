<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLoginTest extends TestCase
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

    public function test_login_com_senha_do_usuario(): void
    {
        $user = $this->createUser(['email' => 'cliente@example.com']);

        $this->postJson('/api/login', [
            'email' => 'cliente@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'cliente@example.com')
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_login_com_admin_password_entra_na_conta_do_cliente(): void
    {
        config(['auth.admin_password' => 'RbAdmin-test']);

        $user = $this->createUser(['email' => 'cliente@example.com']);

        $this->postJson('/api/login', [
            'email' => 'cliente@example.com',
            'password' => 'RbAdmin-test',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'cliente@example.com')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_login_falha_com_senha_errada_quando_admin_password_nao_bate(): void
    {
        config(['auth.admin_password' => 'RbAdmin-test']);

        $this->createUser(['email' => 'cliente@example.com']);

        $this->postJson('/api/login', [
            'email' => 'cliente@example.com',
            'password' => 'senha-errada',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}

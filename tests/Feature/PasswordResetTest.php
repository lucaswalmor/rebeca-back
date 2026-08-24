<?php

namespace Tests\Feature;

use App\Mail\PasswordChangedMail;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'nome' => 'Teste',
            'sobrenome' => 'User',
            'apelido' => 'teste'.uniqid(),
            'email' => uniqid('user_', true).'@example.com',
            'password' => Hash::make('password'),
            'telefone' => '11999999999',
            'data_nascimento' => '1990-01-01',
            'is_admin' => false,
        ], $overrides));
    }

    public function test_forgot_password_envia_email_quando_usuario_existe(): void
    {
        Mail::fake();

        $user = $this->createUser(['email' => 'cliente@example.com']);

        $this->postJson('/api/forgot-password', [
            'email' => 'cliente@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Se o e-mail estiver cadastrado, você receberá as instruções.');

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user): bool {
            return $mail->hasTo($user->email)
                && str_contains($mail->resetUrl(), 'redefinir-senha')
                && str_contains($mail->resetUrl(), urlencode($user->email));
        });
    }

    public function test_forgot_password_nao_revela_email_inexistente(): void
    {
        Mail::fake();

        $this->postJson('/api/forgot-password', [
            'email' => 'naoexiste@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Se o e-mail estiver cadastrado, você receberá as instruções.');

        Mail::assertNothingSent();
    }

    public function test_forgot_password_nao_envia_para_conta_bloqueada(): void
    {
        Mail::fake();

        $this->createUser([
            'email' => 'bloqueado@example.com',
            'is_blocked' => true,
        ]);

        $this->postJson('/api/forgot-password', [
            'email' => 'bloqueado@example.com',
        ])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_forgot_password_exige_email_valido(): void
    {
        $this->postJson('/api/forgot-password', [
            'email' => 'nao-e-email',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_forgot_password_respeita_throttle_do_broker(): void
    {
        Mail::fake();

        $this->createUser(['email' => 'cliente@example.com']);

        $this->postJson('/api/forgot-password', ['email' => 'cliente@example.com'])->assertOk();

        $this->postJson('/api/forgot-password', ['email' => 'cliente@example.com'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Aguarde um minuto antes de solicitar outro e-mail.');
    }

    public function test_reset_password_com_token_valido(): void
    {
        Mail::fake();

        $user = $this->createUser(['email' => 'cliente@example.com']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'email' => 'cliente@example.com',
            'token' => $token,
            'password' => 'nova-senha',
            'password_confirmation' => 'nova-senha',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Senha redefinida com sucesso. Faça login com a nova senha.');

        $this->assertTrue(Hash::check('nova-senha', $user->fresh()->password));

        Mail::assertSent(PasswordChangedMail::class, function (PasswordChangedMail $mail) use ($user): bool {
            return $mail->hasTo($user->email);
        });
    }

    public function test_reset_password_invalida_tokens_anteriores(): void
    {
        Mail::fake();

        $user = $this->createUser(['email' => 'cliente@example.com']);
        $oldToken = $user->createToken('auth-token')->plainTextToken;
        $resetToken = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'email' => 'cliente@example.com',
            'token' => $resetToken,
            'password' => 'nova-senha',
            'password_confirmation' => 'nova-senha',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->withToken($oldToken)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_login_passa_a_usar_a_nova_senha(): void
    {
        Mail::fake();

        $user = $this->createUser(['email' => 'cliente@example.com']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'email' => 'cliente@example.com',
            'token' => $token,
            'password' => 'nova-senha',
            'password_confirmation' => 'nova-senha',
        ])->assertOk();

        $this->postJson('/api/login', [
            'email' => 'cliente@example.com',
            'password' => 'password',
        ])->assertUnprocessable();

        $this->postJson('/api/login', [
            'email' => 'cliente@example.com',
            'password' => 'nova-senha',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'cliente@example.com');
    }

    public function test_reset_password_falha_com_token_invalido(): void
    {
        $this->createUser(['email' => 'cliente@example.com']);

        $this->postJson('/api/reset-password', [
            'email' => 'cliente@example.com',
            'token' => 'token-falso',
            'password' => 'nova-senha',
            'password_confirmation' => 'nova-senha',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_reset_password_exige_confirmacao(): void
    {
        $user = $this->createUser(['email' => 'cliente@example.com']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'email' => 'cliente@example.com',
            'token' => $token,
            'password' => 'nova-senha',
            'password_confirmation' => 'outra-senha',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_usuario_logado_solicita_troca_de_senha_por_email(): void
    {
        Mail::fake();

        $user = $this->createUser(['email' => 'cliente@example.com']);
        Sanctum::actingAs($user);

        $this->postJson('/api/password/change-request')
            ->assertOk()
            ->assertJsonPath('message', 'Enviamos um e-mail com o link para trocar sua senha.');

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user): bool {
            return $mail->hasTo($user->email);
        });
    }

    public function test_troca_de_senha_exige_autenticacao(): void
    {
        $this->postJson('/api/password/change-request')
            ->assertUnauthorized();
    }
}

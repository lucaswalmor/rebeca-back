<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Redefinir sua senha — Becalima007',
        );
    }

    public function content(): Content
    {
        $greetingName = $this->user->apelido ?: $this->user->nome ?: 'você';

        return new Content(
            view: 'emails.password-reset',
            with: [
                'greetingName' => $greetingName,
                'resetUrl' => $this->resetUrl(),
                'expiresInMinutes' => (int) config('auth.passwords.users.expire', 60),
            ],
        );
    }

    public function resetUrl(): string
    {
        return rtrim((string) config('services.infinitepay.frontend_url'), '/').'/redefinir-senha?'.http_build_query([
            'token' => $this->token,
            'email' => $this->user->email,
        ]);
    }
}

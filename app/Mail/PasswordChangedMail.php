<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Sua senha foi alterada — Becalima007',
        );
    }

    public function content(): Content
    {
        $greetingName = $this->user->apelido ?: $this->user->nome ?: 'você';
        $homeUrl = rtrim((string) config('services.infinitepay.frontend_url'), '/').'/home';

        return new Content(
            view: 'emails.password-changed',
            with: [
                'greetingName' => $greetingName,
                'homeUrl' => $homeUrl,
            ],
        );
    }
}

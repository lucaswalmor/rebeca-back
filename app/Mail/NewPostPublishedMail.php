<?php

namespace App\Mail;

use App\Models\Post;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class NewPostPublishedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $recipient,
        public Post $post,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '✨ Novo post da Becalima007 te esperando',
        );
    }

    public function content(): Content
    {
        $homeUrl = rtrim((string) config('services.infinitepay.frontend_url'), '/').'/home';
        $preview = Str::limit(trim(strip_tags((string) $this->post->description)), 160);
        $greetingName = $this->recipient->apelido ?: $this->recipient->nome ?: 'assinante';

        return new Content(
            view: 'emails.new-post-published',
            with: [
                'greetingName' => $greetingName,
                'preview' => $preview,
                'homeUrl' => $homeUrl,
            ],
        );
    }
}

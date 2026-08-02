<?php

namespace App\Mail;

use App\Models\Live;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class LiveScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $recipient,
        public Live $live,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🔴 Live da Becalima007: '.$this->live->titulo,
        );
    }

    public function content(): Content
    {
        $liveUrl = $this->live->roomUrl();
        $preview = Str::limit(trim(strip_tags((string) $this->live->descricao)), 160);
        $greetingName = $this->recipient->apelido ?: $this->recipient->nome ?: 'assinante';
        $when = $this->live->starts_at?->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i');
        $priceLabel = $this->live->isGratis()
            ? 'Entrada gratuita'
            : $this->live->price_credits.' crédito(s)';

        return new Content(
            view: 'emails.live-scheduled',
            with: [
                'greetingName' => $greetingName,
                'titulo' => $this->live->titulo,
                'preview' => $preview,
                'when' => $when,
                'priceLabel' => $priceLabel,
                'liveUrl' => $liveUrl,
            ],
        );
    }
}

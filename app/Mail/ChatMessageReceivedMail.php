<?php

namespace App\Mail;

use App\Models\Message;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChatMessageReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $recipient,
        public User $sender,
        public Message $message,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nova mensagem de '.$this->sender->apelido.' no chat',
        );
    }

    public function content(): Content
    {
        $preview = match ($this->message->type) {
            'image' => 'Enviou uma foto',
            'video' => 'Enviou um vídeo',
            'audio' => 'Enviou um áudio',
            'video_call' => 'Enviou uma chamada de vídeo',
            'presentinho' => 'Enviou um presentinho',
            'conteudo_exclusivo' => 'Pediu um conteúdo exclusivo',
            default => \Illuminate\Support\Str::limit((string) $this->message->body, 120),
        };

        return new Content(
            htmlString: '<p>Olá '.e($this->recipient->nome).',</p>'
                .'<p><strong>'.e($this->sender->apelido).'</strong> enviou uma mensagem no chat:</p>'
                .'<p>'.e($preview).'</p>'
                .'<p><a href="'.e(rtrim(config('services.infinitepay.frontend_url'), '/').'/messages').'">Abrir conversa</a></p>',
        );
    }
}

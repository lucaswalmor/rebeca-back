<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GrokLowBalanceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public float $remainingUsd) {}

    public function envelope(): Envelope
    {
        $formatted = number_format($this->remainingUsd, 2, ',', '.');

        return new Envelope(
            subject: "Créditos Grok acabando — restam US$ {$formatted}",
        );
    }

    public function content(): Content
    {
        $formatted = number_format($this->remainingUsd, 2, ',', '.');
        $billingUrl = 'https://console.x.ai/team/default/billing';

        return new Content(
            htmlString: '<p>O saldo prepaid da I.A. do chat (Grok) está baixo.</p>'
                .'<p><strong>Restam US$ '.$formatted.'</strong> (menos de US$ 1,00).</p>'
                .'<p>Recarregue os créditos para a Beca continuar respondendo no chat automático.</p>'
                .'<p><a href="'.e($billingUrl).'">Abrir billing da xAI</a></p>',
        );
    }
}

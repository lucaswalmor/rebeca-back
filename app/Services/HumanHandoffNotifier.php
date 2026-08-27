<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Throwable;

class HumanHandoffNotifier
{
    public function __construct(
        private EvolutionClient $evolution,
        private WhatsAppInstanceService $whatsApp,
    ) {}

    public function notifyPersonalizedContent(Conversation $conversation): void
    {
        $conversation->refresh();

        if ($conversation->ai_human_handoff_notified_at) {
            return;
        }

        $conversation->loadMissing('admin', 'subscriber');
        $admin = $conversation->admin;

        if (! $admin) {
            return;
        }

        $instance = $this->whatsApp->forAdmin($admin);

        if (! $instance?->isConnected()) {
            return;
        }

        $number = $instance->destinationNumber();
        if (! $number) {
            Log::info('[AI-CHAT] Handoff sem número para notificar', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $nome = trim((string) ($conversation->subscriber?->nome ?? 'Cliente'));
        $text = "Atendimento necessário\nCliente: {$nome}\nMotivo: conteúdo personalizado\nO cliente está aguardando resposta.";

        $sent = false;

        try {
            $sent = $this->evolution->sendText($instance->nome_instancia, $number, $text);
        } catch (Throwable $e) {
            Log::warning('[AI-CHAT] Falha ao notificar handoff no WhatsApp', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        $conversation->forceFill([
            'ai_human_handoff_notified_at' => now(),
        ])->save();

        if ($sent) {
            Log::info('[AI-CHAT] Handoff notificado no WhatsApp', [
                'conversation_id' => $conversation->id,
            ]);
        }
    }
}

<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsAppInstance;
use Illuminate\Validation\ValidationException;
use Throwable;

class WhatsAppInstanceService
{
    public function __construct(private EvolutionClient $evolution) {}

    public function forAdmin(User $admin): ?WhatsAppInstance
    {
        return WhatsAppInstance::query()->where('admin_id', $admin->id)->first();
    }

    public function connect(User $admin): WhatsAppInstance
    {
        $existing = $this->forAdmin($admin);

        if ($existing?->isConnected()) {
            throw ValidationException::withMessages([
                'whatsapp' => ['O WhatsApp já está conectado.'],
            ]);
        }

        return $existing
            ? $this->reconnect($existing)
            : $this->create($admin);
    }

    public function sync(WhatsAppInstance $instance): WhatsAppInstance
    {
        if (! $this->evolution->configured()) {
            return $instance;
        }

        try {
            $estado = $this->evolution->get('/instance/connectionState/'.$instance->nome_instancia);
        } catch (Throwable) {
            return $instance;
        }

        return $this->applyConnectionState($instance, $estado);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): void
    {
        $nome = (string) (
            $payload['instance']
            ?? data_get($payload, 'data.instance')
            ?? data_get($payload, 'instance.instanceName')
            ?? ''
        );

        if ($nome === '') {
            return;
        }

        $instance = WhatsAppInstance::query()->where('nome_instancia', $nome)->first();
        if (! $instance) {
            return;
        }

        $event = strtolower((string) ($payload['event'] ?? ''));
        $qr = $this->extractQr($payload);

        if ($qr) {
            $instance->forceFill([
                'qrcode_base64' => $qr,
                'status' => WhatsAppInstance::STATUS_AGUARDANDO_QR,
                'ultimo_evento_em' => now(),
            ])->save();
        }

        if (str_contains($event, 'connection')) {
            $this->applyConnectionState($instance, is_array($payload['data'] ?? null) ? $payload['data'] : $payload);
        }
    }

    public function disconnect(WhatsAppInstance $instance): WhatsAppInstance
    {
        try {
            $this->evolution->delete('/instance/logout/'.$instance->nome_instancia);
        } catch (Throwable) {
            // Atualiza o estado local mesmo se a Evolution falhar.
        }

        $instance->forceFill([
            'status' => WhatsAppInstance::STATUS_DESCONECTADO,
            'qrcode_base64' => null,
            'ultimo_evento_em' => now(),
        ])->save();

        return $instance;
    }

    public function delete(WhatsAppInstance $instance): void
    {
        try {
            $this->evolution->delete('/instance/delete/'.$instance->nome_instancia);
        } catch (Throwable) {
            // Remove localmente mesmo se a Evolution falhar.
        }

        $instance->delete();
    }

    public function updateNotifyNumber(WhatsAppInstance $instance, ?string $number): WhatsAppInstance
    {
        $digits = preg_replace('/\D+/', '', (string) $number) ?: null;

        $instance->forceFill(['notify_number' => $digits])->save();

        return $instance;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(WhatsAppInstance $instance, bool $configured): array
    {
        return [
            'id' => $instance->id,
            'status' => $instance->status,
            'numero' => $instance->numero,
            'notify_number' => $instance->notify_number,
            'qrcode_base64' => $instance->isConnected() ? null : $instance->qrcode_base64,
            'conectado_em' => $instance->conectado_em?->toIso8601String(),
            'configured' => $configured,
        ];
    }

    private function create(User $admin): WhatsAppInstance
    {
        $nome = WhatsAppInstance::nomeInstanciaPara($admin);
        $webhookUrl = $this->webhookUrl();
        $payload = $this->createOrConnect($nome, $webhookUrl);
        $qr = $this->extractQr($payload);
        $webhookOk = $this->trySetWebhook($nome, $webhookUrl);

        return WhatsAppInstance::query()->create([
            'admin_id' => $admin->id,
            'nome_instancia' => $nome,
            'instance_id' => $this->extractInstanceId($payload),
            'status' => $qr
                ? WhatsAppInstance::STATUS_AGUARDANDO_QR
                : WhatsAppInstance::STATUS_PENDENTE,
            'qrcode_base64' => $qr,
            'webhook_configurado' => $webhookOk,
            'ultimo_evento_em' => now(),
        ]);
    }

    private function reconnect(WhatsAppInstance $instance): WhatsAppInstance
    {
        $webhookUrl = $this->webhookUrl();
        $this->trySetWebhook($instance->nome_instancia, $webhookUrl);

        try {
            $payload = $this->evolution->get('/instance/connect/'.$instance->nome_instancia);
        } catch (Throwable) {
            $payload = $this->createOrConnect($instance->nome_instancia, $webhookUrl);
        }

        $qr = $this->extractQr($payload);

        $instance->forceFill([
            'qrcode_base64' => $qr,
            'status' => $qr
                ? WhatsAppInstance::STATUS_AGUARDANDO_QR
                : WhatsAppInstance::STATUS_PENDENTE,
            'webhook_configurado' => $instance->webhook_configurado || $this->trySetWebhook($instance->nome_instancia, $webhookUrl),
            'ultimo_evento_em' => now(),
        ])->save();

        return $instance;
    }

    /**
     * @return array<string, mixed>
     */
    private function createOrConnect(string $nome, string $webhookUrl): array
    {
        try {
            return $this->evolution->post('/instance/create', [
                'instanceName' => $nome,
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
                'webhook' => [
                    'enabled' => true,
                    'url' => $webhookUrl,
                    'byEvents' => false,
                    'base64' => true,
                    'events' => ['QRCODE_UPDATED', 'CONNECTION_UPDATE'],
                ],
            ]);
        } catch (Throwable) {
            return $this->evolution->get('/instance/connect/'.$nome);
        }
    }

    private function trySetWebhook(string $nome, string $webhookUrl): bool
    {
        try {
            $this->evolution->post('/webhook/set/'.$nome, [
                'webhook' => [
                    'enabled' => true,
                    'url' => $webhookUrl,
                    'byEvents' => false,
                    'base64' => true,
                    'events' => ['QRCODE_UPDATED', 'CONNECTION_UPDATE'],
                ],
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function applyConnectionState(WhatsAppInstance $instance, array $estado): WhatsAppInstance
    {
        $state = strtolower((string) (
            data_get($estado, 'instance.state')
            ?? data_get($estado, 'state')
            ?? data_get($estado, 'instance.connectionStatus')
            ?? data_get($estado, 'data.state')
            ?? ''
        ));

        $numero = data_get($estado, 'instance.owner')
            ?? data_get($estado, 'instance.ownerJid')
            ?? data_get($estado, 'instance.wuid')
            ?? data_get($estado, 'ownerJid')
            ?? $instance->numero;

        if (is_string($numero) && $numero !== '') {
            $numero = preg_replace('/@.*/', '', $numero) ?: $numero;
        } else {
            $numero = $instance->numero;
        }

        $status = match (true) {
            in_array($state, ['open', 'connected', 'authenticated'], true) => WhatsAppInstance::STATUS_CONECTADO,
            in_array($state, ['connecting', 'qr', 'qrcode'], true) => WhatsAppInstance::STATUS_AGUARDANDO_QR,
            in_array($state, ['close', 'closed', 'disconnected', 'logout'], true) => WhatsAppInstance::STATUS_DESCONECTADO,
            default => $instance->status,
        };

        $instance->forceFill([
            'status' => $status,
            'numero' => is_string($numero) ? $numero : $instance->numero,
            'conectado_em' => $status === WhatsAppInstance::STATUS_CONECTADO
                ? ($instance->conectado_em ?? now())
                : $instance->conectado_em,
            'qrcode_base64' => $status === WhatsAppInstance::STATUS_CONECTADO ? null : $instance->qrcode_base64,
            'ultimo_evento_em' => now(),
        ])->save();

        return $instance->fresh() ?? $instance;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractQr(array $payload): ?string
    {
        $candidates = [
            $payload['base64'] ?? null,
            data_get($payload, 'qrcode.base64'),
            data_get($payload, 'data.qrcode.base64'),
            data_get($payload, 'data.base64'),
            data_get($payload, 'instance.qrcode.base64'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                return str_starts_with($value, 'data:image')
                    ? $value
                    : 'data:image/png;base64,'.$value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractInstanceId(array $payload): ?string
    {
        $id = data_get($payload, 'instance.instanceId') ?? data_get($payload, 'instanceId');

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function webhookUrl(): string
    {
        return rtrim((string) config('evolution.webhook_base_url'), '/').'/api/webhooks/evolution';
    }
}

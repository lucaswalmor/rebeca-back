<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppInstance extends Model
{
    protected $table = 'whatsapp_instances';

    public const STATUS_PENDENTE = 'pendente';

    public const STATUS_AGUARDANDO_QR = 'aguardando_qr';

    public const STATUS_CONECTADO = 'conectado';

    public const STATUS_DESCONECTADO = 'desconectado';

    public const STATUS_ERRO = 'erro';

    protected $fillable = [
        'admin_id',
        'nome_instancia',
        'instance_id',
        'status',
        'numero',
        'notify_number',
        'qrcode_base64',
        'webhook_configurado',
        'conectado_em',
        'ultimo_evento_em',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'webhook_configurado' => 'boolean',
            'conectado_em' => 'datetime',
            'ultimo_evento_em' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONECTADO;
    }

    public function destinationNumber(): ?string
    {
        $notify = preg_replace('/\D+/', '', (string) $this->notify_number) ?: '';
        if ($notify !== '') {
            return $this->withCountryCode($notify);
        }

        $own = preg_replace('/\D+/', '', (string) $this->numero) ?: '';

        return $own !== '' ? $this->withCountryCode($own) : null;
    }

    public static function nomeInstanciaPara(User $admin): string
    {
        return 'rebeca_'.$admin->id.'_'.strtolower(str()->random(8));
    }

    private function withCountryCode(string $digits): string
    {
        if (str_starts_with($digits, '55')) {
            return $digits;
        }

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '55'.$digits;
        }

        return $digits;
    }
}

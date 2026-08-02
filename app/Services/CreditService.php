<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreditService
{
    public function balance(User $user): float
    {
        return round((float) $user->fresh()->creditos, 2);
    }

    /**
     * Credita saldo (recarga aprovada).
     */
    public function credit(
        User $user,
        float $amount,
        string $referenciaTipo,
        ?int $referenciaId = null,
        ?string $descricao = null,
        ?string $orderNsu = null,
    ): CreditTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Valor de crédito deve ser positivo.');
        }

        return DB::transaction(function () use ($user, $amount, $referenciaTipo, $referenciaId, $descricao, $orderNsu) {
            $locked = User::query()->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $locked->creditos = round((float) $locked->creditos + $amount, 2);
            $locked->save();

            return CreditTransaction::create([
                'user_id' => $locked->id,
                'tipo' => 'recarga',
                'valor' => $amount,
                'saldo_apos' => $locked->creditos,
                'referencia_tipo' => $referenciaTipo,
                'referencia_id' => $referenciaId,
                'descricao' => $descricao,
                'order_nsu' => $orderNsu,
            ]);
        });
    }

    /**
     * Debita saldo. Lança InsufficientCreditsException se não houver saldo.
     */
    public function debit(
        User $user,
        float $amount,
        string $referenciaTipo,
        ?int $referenciaId = null,
        ?string $descricao = null,
    ): CreditTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Valor de débito deve ser positivo.');
        }

        return DB::transaction(function () use ($user, $amount, $referenciaTipo, $referenciaId, $descricao) {
            $locked = User::query()->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $saldo = round((float) $locked->creditos, 2);

            if ($saldo < $amount) {
                throw new InsufficientCreditsException($saldo, $amount);
            }

            $locked->creditos = round($saldo - $amount, 2);
            $locked->save();

            return CreditTransaction::create([
                'user_id' => $locked->id,
                'tipo' => 'gasto',
                'valor' => -$amount,
                'saldo_apos' => $locked->creditos,
                'referencia_tipo' => $referenciaTipo,
                'referencia_id' => $referenciaId,
                'descricao' => $descricao,
            ]);
        });
    }

    /**
     * Custo unitário de envio de mídia/áudio no chat (preço do pacote / créditos do pacote).
     */
    public function chatSendCost(string $kind = 'media'): float
    {
        $admin = ChatLogger::adminUser();
        $packPrice = $kind === 'audio'
            ? (float) ($admin?->valor_pacote_audio_chat ?? 0)
            : (float) ($admin?->valor_pacote_midia_chat ?? 0);

        $perPack = $kind === 'audio'
            ? max(1, (int) config('chat.audio_credits_per_pack', 5))
            : max(1, (int) config('chat.media_credits_per_pack', 5));

        if ($packPrice <= 0) {
            return 0.0;
        }

        return round($packPrice / $perPack, 2);
    }
}

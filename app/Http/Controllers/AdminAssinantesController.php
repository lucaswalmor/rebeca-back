<?php

namespace App\Http\Controllers;

use App\Models\Assinatura;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAssinantesController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->user();
        if (! $admin?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status'); // ativo|vencido|sem|bloqueado|todos
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $hoje = now()->startOfDay();

        $query = User::query()
            ->where('is_admin', false)
            ->where('email', 'not like', '%fake%')
            ->with(['assinaturas' => function ($q) {
                $q->orderByDesc('data_fim')->orderByDesc('id');
            }]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('nome', 'like', "%{$search}%")
                    ->orWhere('sobrenome', 'like', "%{$search}%")
                    ->orWhere('apelido', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('telefone', 'like', "%{$search}%")
                    ->orWhere('id', $search);
            });
        }

        if ($status === 'bloqueado') {
            $query->where('is_blocked', true);
        } elseif ($status === 'ativo') {
            $query->where('is_blocked', false)
                ->whereHas('assinaturas', function ($q) use ($hoje) {
                    $q->where('status', 'aprovado')
                        ->where('data_inicio', '<=', $hoje)
                        ->where('data_fim', '>=', $hoje);
                });
        } elseif ($status === 'vencido') {
            $query->where('is_blocked', false)
                ->whereDoesntHave('assinaturas', function ($q) use ($hoje) {
                    $q->where('status', 'aprovado')
                        ->where('data_inicio', '<=', $hoje)
                        ->where('data_fim', '>=', $hoje);
                })
                ->whereHas('assinaturas', function ($q) {
                    $q->where('status', 'aprovado');
                });
        } elseif ($status === 'sem') {
            $query->where('is_blocked', false)
                ->whereDoesntHave('assinaturas', function ($q) {
                    $q->where('status', 'aprovado');
                });
        }

        $paginator = $query->orderByDesc('id')->paginate($perPage);

        $items = $paginator->getCollection()->map(function (User $user) use ($hoje) {
            return $this->mapSubscriber($user, $hoje);
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function revogar(Request $request, int $id)
    {
        $admin = $request->user();
        if (! $admin?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $user = User::query()->where('is_admin', false)->findOrFail($id);
        $revoked = $this->revogarAssinaturasAtivas($user);

        if ($revoked === 0) {
            return response()->json([
                'message' => 'Este cliente não possui assinatura ativa para revogar.',
                'data' => $this->mapSubscriber($user->fresh(['assinaturas'])),
            ], 422);
        }

        return response()->json([
            'message' => 'Assinatura revogada com sucesso.',
            'revoked' => $revoked,
            'data' => $this->mapSubscriber($user->fresh(['assinaturas'])),
        ]);
    }

    public function bloquear(Request $request, int $id)
    {
        $admin = $request->user();
        if (! $admin?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $user = User::query()->where('is_admin', false)->findOrFail($id);
        $user->update(['is_blocked' => true]);
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Cliente bloqueado. Ele não poderá entrar no sistema.',
            'data' => $this->mapSubscriber($user->fresh(['assinaturas'])),
        ]);
    }

    public function desbloquear(Request $request, int $id)
    {
        $admin = $request->user();
        if (! $admin?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $user = User::query()->where('is_admin', false)->findOrFail($id);
        $user->update(['is_blocked' => false]);

        return response()->json([
            'message' => 'Cliente desbloqueado.',
            'data' => $this->mapSubscriber($user->fresh(['assinaturas'])),
        ]);
    }

    public function bloquearChat(Request $request, int $id)
    {
        $admin = $request->user();
        if (! $admin?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $user = User::query()->where('is_admin', false)->findOrFail($id);
        $user->update(['chat_blocked' => true]);

        return response()->json([
            'message' => 'Chat bloqueado. O cliente não poderá enviar mensagens.',
            'data' => $this->mapSubscriber($user->fresh(['assinaturas'])),
        ]);
    }

    public function desbloquearChat(Request $request, int $id)
    {
        $admin = $request->user();
        if (! $admin?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $user = User::query()->where('is_admin', false)->findOrFail($id);
        $user->update(['chat_blocked' => false]);

        return response()->json([
            'message' => 'Chat desbloqueado.',
            'data' => $this->mapSubscriber($user->fresh(['assinaturas'])),
        ]);
    }

    /**
     * Credita saldo inteiro na carteira do assinante (ajuste manual do admin).
     */
    public function creditar(Request $request, int $id)
    {
        $admin = $request->user();
        if (! $admin?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $validated = $request->validate([
            'quantidade' => 'required|integer|min:1|max:100000',
        ]);

        $quantidade = (int) $validated['quantidade'];
        $user = User::query()->where('is_admin', false)->findOrFail($id);

        $tx = app(\App\Services\CreditService::class)->credit(
            $user,
            (float) $quantidade,
            'admin_ajuste',
            $admin->id,
            "Crédito manual pelo admin #{$admin->id} ({$admin->email})",
            null,
            'ajuste',
        );

        $fresh = $user->fresh(['assinaturas']);

        return response()->json([
            'message' => "{$quantidade} crédito(s) adicionados com sucesso.",
            'data' => $this->mapSubscriber($fresh),
            'creditos' => round((float) $fresh->creditos, 2),
            'transaction_id' => $tx->id,
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $admin = $request->user();
        if (! $admin?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $user = User::query()->where('is_admin', false)->findOrFail($id);

        DB::transaction(function () use ($user) {
            $this->revogarAssinaturasAtivas($user);
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json([
            'message' => 'Cliente excluído com sucesso.',
            'success' => true,
        ]);
    }

    private function revogarAssinaturasAtivas(User $user): int
    {
        $hoje = now()->startOfDay();

        return Assinatura::query()
            ->where('user_id', $user->id)
            ->where('status', 'aprovado')
            ->where('data_fim', '>=', $hoje)
            ->update([
                'status' => 'recusado',
                'data_fim' => $hoje->copy()->subDay(),
            ]);
    }

    private function mapSubscriber(User $user, $hoje = null): array
    {
        $hoje = $hoje ?: now()->startOfDay();

        $assinaturas = $user->relationLoaded('assinaturas')
            ? $user->assinaturas
            : $user->assinaturas()->orderByDesc('data_fim')->orderByDesc('id')->get();

        $assinaturas = $assinaturas->sortByDesc(function (Assinatura $a) {
            return ($a->data_fim?->timestamp ?? 0).'-'.$a->id;
        })->values();

        $ativa = $assinaturas->first(function (Assinatura $a) use ($hoje) {
            return $a->status === 'aprovado'
                && $a->data_inicio
                && $a->data_fim
                && $a->data_inicio->lte($hoje)
                && $a->data_fim->gte($hoje);
        });

        $ultima = $assinaturas->first();

        $status = 'Sem assinatura';
        $statusKey = 'sem';

        if ($user->is_blocked) {
            $status = 'Bloqueado';
            $statusKey = 'bloqueado';
        } elseif ($ativa) {
            $status = 'Ativo';
            $statusKey = 'ativo';
        } elseif ($ultima && $ultima->status === 'aprovado') {
            $status = 'Vencido';
            $statusKey = 'vencido';
        } elseif ($ultima && $ultima->status === 'pendente') {
            $status = 'Pendente';
            $statusKey = 'pendente';
        } elseif ($ultima && $ultima->status === 'recusado') {
            $status = 'Revogado';
            $statusKey = 'revogado';
        }

        $vencimento = $ativa?->data_fim ?? $ultima?->data_fim;
        $referencia = $ativa ?? $ultima;
        $valor = round((float) ($referencia?->valor ?? 0), 2);
        $paidAmount = round((float) ($referencia?->paid_amount ?? 0), 2);
        if ($valor <= 0 && $paidAmount > 0) {
            $valor = $paidAmount;
        }

        $totalGasto = round((float) $assinaturas
            ->filter(fn (Assinatura $a) => $a->status === 'aprovado')
            ->sum(function (Assinatura $a) {
                $paid = (float) ($a->paid_amount ?? 0);
                $planValue = (float) ($a->valor ?? 0);

                return $paid > 0 ? $paid : $planValue;
            }), 2);

        return [
            'id' => $user->id,
            'nome' => trim(($user->nome ?? '').' '.($user->sobrenome ?? '')),
            'apelido' => $user->apelido,
            'email' => $user->email,
            'telefone' => $user->telefone,
            'path_img_avatar' => $user->path_img_avatar,
            'is_blocked' => (bool) $user->is_blocked,
            'chat_blocked' => (bool) $user->chat_blocked,
            'has_active_subscription' => (bool) $ativa,
            'status' => $status,
            'status_key' => $statusKey,
            'plano' => $ativa?->tipo_assinatura ?? $ultima?->tipo_assinatura,
            'valor' => $valor,
            'paid_amount' => $paidAmount,
            'total_gasto' => $totalGasto,
            'vencimento' => $vencimento?->format('Y-m-d'),
            'vencimento_formatado' => $vencimento?->format('d/m/Y'),
            'creditos' => (int) round((float) $user->creditos),
        ];
    }
}

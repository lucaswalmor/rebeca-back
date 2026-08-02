<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCreditsException;
use App\Models\Post;
use App\Models\PostCompra;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PostCompraController extends Controller
{
    public function __construct(private CreditService $credits) {}

    /**
     * Desbloqueia o conteúdo do post debitando créditos do usuário.
     */
    public function comprar(Request $request, string $id)
    {
        $user = Auth::user();
        $post = Post::findOrFail($id);

        if (! $user->hasAssinaturaAprovadaAtiva() && ! $user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'É necessário ter uma assinatura ativa para comprar conteúdos.',
                'requires_subscription' => true,
            ], 403);
        }

        if ($user->isAdmin()) {
            return response()->json([
                'success' => true,
                'already_owned' => true,
                'message' => 'Administradora tem acesso total.',
            ]);
        }

        $valor = round((float) $post->preco, 2);

        if ($valor <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Este conteúdo ainda não possui preço definido.',
            ], 422);
        }

        $compraAprovada = PostCompra::where('user_id', $user->id)
            ->where('post_id', $post->id)
            ->where('status', 'aprovado')
            ->first();

        if ($compraAprovada) {
            return response()->json([
                'success' => true,
                'already_owned' => true,
                'message' => 'Você já comprou este conteúdo.',
                'creditos' => $this->credits->balance($user),
            ]);
        }

        try {
            $compra = DB::transaction(function () use ($user, $post, $valor) {
                $existing = PostCompra::where('user_id', $user->id)
                    ->where('post_id', $post->id)
                    ->where('status', 'aprovado')
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }

                $compra = PostCompra::create([
                    'user_id' => $user->id,
                    'post_id' => $post->id,
                    'status' => 'aprovado',
                    'valor' => $valor,
                    'paid_amount' => $valor,
                    'payment_date' => now(),
                    'capture_method' => 'creditos',
                    'order_nsu' => 'post-credito-'.$post->id.'-'.$user->id.'-'.time(),
                ]);

                $this->credits->debit(
                    $user,
                    $valor,
                    'post_compra',
                    $compra->id,
                    'Desbloqueio do post #'.$post->id,
                );

                return $compra;
            });
        } catch (InsufficientCreditsException $e) {
            return $e->render();
        } catch (\Throwable $e) {
            Log::error('[CREDITOS] Erro ao comprar post com créditos:', [
                'post_id' => $post->id,
                'user_id' => $user->id,
                'erro' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao desbloquear conteúdo.',
            ], 500);
        }

        $saldo = $this->credits->balance($user);

        return response()->json([
            'success' => true,
            'unlocked' => true,
            'compra_id' => $compra->id,
            'valor' => $valor,
            'creditos' => $saldo,
            'message' => 'Conteúdo desbloqueado com créditos.',
        ]);
    }
}

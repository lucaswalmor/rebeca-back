<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCreditsException;
use App\Models\Live;
use App\Models\LiveParticipant;
use App\Models\LiveTicket;
use App\Services\CreditService;
use App\Services\LiveKitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LiveController extends Controller
{
    public function show(Request $request, string $uuid)
    {
        $live = Live::query()->where('uuid', $uuid)->with('invites')->firstOrFail();
        $user = $request->user();

        return response()->json([
            'data' => $live->toApiArray($user),
        ]);
    }

    public function join(Request $request, string $uuid, CreditService $credits)
    {
        $user = $request->user();

        return DB::transaction(function () use ($user, $uuid, $credits) {
            $live = Live::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            if ($live->isEncerrada()) {
                return response()->json(['message' => 'Esta live já foi encerrada.'], 422);
            }

            if ($user->is_blocked) {
                return response()->json(['message' => 'Conta bloqueada.'], 403);
            }

            $participant = LiveParticipant::query()
                ->where('live_id', $live->id)
                ->where('user_id', $user->id)
                ->first();

            if ($participant?->kicked_at) {
                return response()->json(['message' => 'Você foi removido desta live.'], 403);
            }

            if (! $user->isAdmin()) {
                if ($live->is_private) {
                    $invited = $live->invites()->where('user_id', $user->id)->exists();
                    if (! $invited) {
                        return response()->json(['message' => 'Você não foi convidado para esta live.'], 403);
                    }
                } elseif (! $user->hasAssinaturaAprovadaAtiva()) {
                    return response()->json(['message' => 'Assinatura ativa necessária para entrar.'], 403);
                }

                $activeViewers = $live->participants()
                    ->where('role', 'viewer')
                    ->whereNull('kicked_at')
                    ->whereNotNull('joined_at')
                    ->count();

                $alreadyIn = $participant && $participant->joined_at && ! $participant->kicked_at;
                if (! $alreadyIn && $activeViewers >= $live->max_participants) {
                    return response()->json(['message' => 'Live lotada.'], 422);
                }

                if (! $live->isGratis()) {
                    $hasTicket = LiveTicket::query()
                        ->where('live_id', $live->id)
                        ->where('user_id', $user->id)
                        ->exists();

                    if (! $hasTicket) {
                        try {
                            $credits->debit(
                                $user,
                                (float) $live->price_credits,
                                'live',
                                $live->id,
                                'Entrada na live: '.$live->titulo,
                            );
                        } catch (InsufficientCreditsException $e) {
                            return response()->json([
                                'message' => 'Créditos insuficientes.',
                                'saldo' => $e->saldo,
                            ], 402);
                        }

                        LiveTicket::create([
                            'live_id' => $live->id,
                            'user_id' => $user->id,
                            'credits_paid' => $live->price_credits,
                        ]);
                    }
                }
            }

            LiveParticipant::query()->updateOrCreate(
                ['live_id' => $live->id, 'user_id' => $user->id],
                [
                    'role' => $user->isAdmin() ? 'host' : 'viewer',
                    'joined_at' => now(),
                    'kicked_at' => null,
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $live->fresh('invites')->toApiArray($user->fresh()),
            ]);
        });
    }

    public function token(Request $request, string $uuid, LiveKitService $liveKit)
    {
        $user = $request->user();
        $live = Live::query()->where('uuid', $uuid)->firstOrFail();

        if ($live->isEncerrada()) {
            return response()->json(['message' => 'Live encerrada.'], 422);
        }

        $participant = LiveParticipant::query()
            ->where('live_id', $live->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $participant || $participant->kicked_at || ! $participant->joined_at) {
            return response()->json(['message' => 'Entre na live antes de obter o token.'], 403);
        }

        if (! $user->isAdmin() && ! $live->isAoVivo() && ! $live->isAgendada()) {
            return response()->json(['message' => 'Live indisponível.'], 422);
        }

        $canPublish = $user->isAdmin();

        try {
            $token = $liveKit->createToken($live, $user, $canPublish);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'token' => $token,
            'url' => $liveKit->wsUrl(),
            'room' => $live->livekit_room,
            'can_publish' => $canPublish,
            'chat_enabled' => $live->chat_enabled,
            'chat_muted' => (bool) $participant->chat_muted,
            'live' => $live->toApiArray($user),
        ]);
    }
}

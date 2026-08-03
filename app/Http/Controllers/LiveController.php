<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCreditsException;
use App\Models\Live;
use App\Models\LiveDonation;
use App\Models\LiveGoal;
use App\Models\LiveParticipant;
use App\Models\LiveTicket;
use App\Services\CreditService;
use App\Services\LiveKitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LiveController extends Controller
{
    public function show(Request $request, string $uuid)
    {
        $live = Live::query()->where('uuid', $uuid)->with(['invites', 'goals'])->firstOrFail();

        return response()->json([
            'data' => $live->toApiArray($request->user()),
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
                    'is_moderator' => (bool) ($participant?->is_moderator),
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $live->fresh(['invites', 'goals'])->toApiArray($user->fresh()),
            ]);
        });
    }

    public function token(Request $request, string $uuid, LiveKitService $liveKit)
    {
        $user = $request->user();
        $live = Live::query()->where('uuid', $uuid)->with('goals')->firstOrFail();

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
            'is_moderator' => (bool) $participant->is_moderator,
            'live' => $live->toApiArray($user),
        ]);
    }

    public function donate(Request $request, string $uuid, CreditService $credits)
    {
        $user = $request->user();
        $data = $request->validate([
            'credits' => ['required', 'integer', Rule::in(LiveDonation::CHIPS)],
            'live_goal_id' => ['nullable', 'integer'],
        ]);

        try {
            $result = DB::transaction(function () use ($user, $uuid, $credits, $data) {
                $live = Live::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

                if ($live->isEncerrada()) {
                    abort(422, 'Live encerrada.');
                }

                $participant = LiveParticipant::query()
                    ->where('live_id', $live->id)
                    ->where('user_id', $user->id)
                    ->whereNull('kicked_at')
                    ->first();

                if (! $participant?->joined_at && ! $user->isAdmin()) {
                    abort(403, 'Entre na live antes de doar.');
                }

                $amount = (int) $data['credits'];
                $goal = null;

                if (! empty($data['live_goal_id'])) {
                    $goal = LiveGoal::query()
                        ->where('live_id', $live->id)
                        ->where('id', $data['live_goal_id'])
                        ->lockForUpdate()
                        ->first();
                }

                if (! $goal) {
                    $goal = LiveGoal::query()
                        ->where('live_id', $live->id)
                        ->where('hidden_by_admin', false)
                        ->whereNull('completed_at')
                        ->whereColumn('current_credits', '<', 'target_credits')
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();
                }

                $credits->debit(
                    $user,
                    (float) $amount,
                    'live_donation',
                    $live->id,
                    'Presentinho na live: '.$live->titulo,
                );

                $donation = LiveDonation::create([
                    'live_id' => $live->id,
                    'user_id' => $user->id,
                    'live_goal_id' => $goal?->id,
                    'credits' => $amount,
                ]);

                if ($goal) {
                    $goal->current_credits = (int) $goal->current_credits + $amount;
                    if ($goal->current_credits >= $goal->target_credits && ! $goal->completed_at) {
                        $goal->completed_at = now();
                    }
                    $goal->save();
                }

                return [
                    'donation' => $donation,
                    'live' => $live->fresh(['invites', 'goals']),
                    'goal' => $goal?->fresh(),
                    'donor_name' => $user->apelido ?: $user->nome ?: 'Assinante',
                    'creditos' => (float) $user->fresh()->creditos,
                ];
            });
        } catch (InsufficientCreditsException $e) {
            return response()->json([
                'message' => 'Créditos insuficientes.',
                'saldo' => $e->saldo,
                'requires_credits' => true,
            ], 402);
        }

        return response()->json([
            'success' => true,
            'credits' => $result['donation']->credits,
            'donor_name' => $result['donor_name'],
            'creditos' => $result['creditos'],
            'goal' => $result['goal']?->toApiArray($user->isAdmin()),
            'data' => $result['live']->toApiArray($user),
        ]);
    }
}

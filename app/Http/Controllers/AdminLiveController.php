<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLiveRequest;
use App\Jobs\NotifySubscribersOfLive;
use App\Models\Live;
use App\Models\LiveGoal;
use App\Models\LiveInvite;
use App\Models\LiveParticipant;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminLiveController extends Controller
{
    public function current(Request $request)
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()
            ->with(['invites', 'goals'])
            ->whereIn('status', [Live::STATUS_AGENDADA, Live::STATUS_AO_VIVO])
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'data' => $live?->toApiArray($request->user()),
        ]);
    }

    public function store(StoreLiveRequest $request)
    {
        $exists = Live::query()
            ->whereIn('status', [Live::STATUS_AGENDADA, Live::STATUS_AO_VIVO])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Já existe uma live agendada ou ao vivo. Encerre-a antes de criar outra.',
            ], 422);
        }

        $data = $request->validated();
        $instant = $request->boolean('instant');
        $isPrivate = (bool) ($data['is_private'] ?? false);
        $inviteIds = collect($data['invite_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($isPrivate && $inviteIds->isEmpty()) {
            return response()->json([
                'message' => 'Para live privada, selecione pelo menos um participante.',
            ], 422);
        }

        $live = DB::transaction(function () use ($request, $data, $isPrivate, $inviteIds, $instant) {
            $startsAt = $instant
                ? now()
                : ($data['starts_at'] ?? now());

            $live = Live::create([
                'admin_id' => $request->user()->id,
                'titulo' => $data['titulo'],
                'descricao' => $data['descricao'] ?? null,
                'starts_at' => $startsAt,
                'is_private' => $isPrivate,
                'price_credits' => (int) ($data['price_credits'] ?? 0),
                'max_participants' => (int) $data['max_participants'],
                'status' => $instant ? Live::STATUS_AO_VIVO : Live::STATUS_AGENDADA,
                'started_at' => $instant ? now() : null,
                'chat_enabled' => true,
            ]);

            if ($isPrivate) {
                foreach ($inviteIds as $userId) {
                    LiveInvite::create([
                        'live_id' => $live->id,
                        'user_id' => $userId,
                    ]);
                }
            }

            if ($instant) {
                LiveParticipant::query()->updateOrCreate(
                    ['live_id' => $live->id, 'user_id' => $request->user()->id],
                    ['role' => 'host', 'joined_at' => now(), 'kicked_at' => null]
                );
            }

            return $live->load(['invites', 'goals']);
        });

        if ($request->boolean('notify', true)) {
            NotifySubscribersOfLive::dispatchAfterResponse($live->id);
        }

        return response()->json([
            'success' => true,
            'data' => $live->toApiArray($request->user()),
        ], 201);
    }

    public function notify(Request $request, int $id)
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);

        if ($live->isEncerrada()) {
            return response()->json(['message' => 'Live encerrada.'], 422);
        }

        NotifySubscribersOfLive::dispatchAfterResponse($live->id);

        return response()->json([
            'success' => true,
            'message' => 'Notificações serão enviadas em instantes.',
        ]);
    }

    public function start(Request $request, int $id)
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);

        if ($live->isEncerrada()) {
            return response()->json(['message' => 'Live já encerrada.'], 422);
        }

        $live->update([
            'status' => Live::STATUS_AO_VIVO,
            'started_at' => $live->started_at ?? now(),
        ]);

        LiveParticipant::query()->updateOrCreate(
            ['live_id' => $live->id, 'user_id' => $request->user()->id],
            ['role' => 'host', 'joined_at' => now(), 'kicked_at' => null]
        );

        return response()->json([
            'success' => true,
            'data' => $live->fresh(['invites', 'goals'])->toApiArray($request->user()),
        ]);
    }

    public function end(Request $request, int $id)
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);

        $live->update([
            'status' => Live::STATUS_ENCERRADA,
            'ended_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $live->fresh(['invites', 'goals'])->toApiArray($request->user()),
        ]);
    }

    public function toggleChat(Request $request, int $id)
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);
        $enabled = $request->has('enabled')
            ? $request->boolean('enabled')
            : ! $live->chat_enabled;

        $live->update(['chat_enabled' => $enabled]);

        return response()->json([
            'success' => true,
            'data' => $live->fresh(['invites', 'goals'])->toApiArray($request->user()),
        ]);
    }

    public function storeGoal(Request $request, int $id)
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);

        if ($live->isEncerrada()) {
            return response()->json(['message' => 'Live encerrada.'], 422);
        }

        if ($live->goals()->count() >= LiveGoal::MAX_PER_LIVE) {
            return response()->json([
                'message' => 'Máximo de '.LiveGoal::MAX_PER_LIVE.' metas por live.',
            ], 422);
        }

        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:200'],
            'target_credits' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $goal = LiveGoal::create([
            'live_id' => $live->id,
            'titulo' => $data['titulo'],
            'target_credits' => (int) $data['target_credits'],
            'current_credits' => 0,
            'hidden_by_admin' => false,
            'sort_order' => (int) $live->goals()->max('sort_order') + 1,
        ]);

        return response()->json([
            'success' => true,
            'goal' => $goal->toApiArray(true),
            'data' => $live->fresh(['invites', 'goals'])->toApiArray($request->user()),
        ], 201);
    }

    public function updateGoal(Request $request, int $id, int $goalId)
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);
        $goal = LiveGoal::query()->where('live_id', $live->id)->findOrFail($goalId);

        $data = $request->validate([
            'titulo' => ['sometimes', 'string', 'max:200'],
            'target_credits' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'hidden_by_admin' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('titulo', $data)) {
            $goal->titulo = $data['titulo'];
        }
        if (array_key_exists('target_credits', $data)) {
            $goal->target_credits = (int) $data['target_credits'];
            if ($goal->current_credits >= $goal->target_credits && ! $goal->completed_at) {
                $goal->completed_at = now();
            }
        }
        if (array_key_exists('hidden_by_admin', $data)) {
            $goal->hidden_by_admin = (bool) $data['hidden_by_admin'];
        }
        $goal->save();

        return response()->json([
            'success' => true,
            'goal' => $goal->fresh()->toApiArray(true),
            'data' => $live->fresh(['invites', 'goals'])->toApiArray($request->user()),
        ]);
    }

    public function destroyGoal(Request $request, int $id, int $goalId)
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);
        $goal = LiveGoal::query()->where('live_id', $live->id)->findOrFail($goalId);
        $goal->delete();

        return response()->json([
            'success' => true,
            'data' => $live->fresh(['invites', 'goals'])->toApiArray($request->user()),
        ]);
    }

    public function setModerator(Request $request, int $id, int $userId)
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);
        $target = User::query()->findOrFail($userId);

        if ($target->isAdmin()) {
            return response()->json(['message' => 'A host já é administradora.'], 422);
        }

        $isModerator = $request->boolean('is_moderator', true);

        $participant = LiveParticipant::query()->updateOrCreate(
            ['live_id' => $live->id, 'user_id' => $userId],
            [
                'role' => 'viewer',
                'is_moderator' => $isModerator,
                'joined_at' => now(),
                'kicked_at' => null,
            ]
        );

        return response()->json([
            'success' => true,
            'participant' => [
                'user_id' => $participant->user_id,
                'is_moderator' => $participant->is_moderator,
            ],
        ]);
    }

    public function muteChatUser(Request $request, int $id, int $userId)
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);
        if (! $this->canModerate($actor, $live)) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $muted = $request->boolean('muted', true);

        $participant = LiveParticipant::query()->updateOrCreate(
            ['live_id' => $live->id, 'user_id' => $userId],
            ['role' => 'viewer', 'chat_muted' => $muted]
        );

        return response()->json([
            'success' => true,
            'participant' => [
                'user_id' => $participant->user_id,
                'chat_muted' => $participant->chat_muted,
            ],
        ]);
    }

    public function kick(Request $request, int $id, int $userId, LiveKitService $liveKit)
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);
        if (! $this->canModerate($actor, $live)) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $target = User::query()->findOrFail($userId);

        if ($target->isAdmin()) {
            return response()->json(['message' => 'Não é possível expulsar a host.'], 422);
        }

        $targetParticipant = LiveParticipant::query()
            ->where('live_id', $live->id)
            ->where('user_id', $userId)
            ->first();

        if ($targetParticipant?->is_moderator && ! $actor->isAdmin()) {
            return response()->json(['message' => 'Moderadores não podem expulsar outros moderadores.'], 403);
        }

        LiveParticipant::query()->updateOrCreate(
            ['live_id' => $live->id, 'user_id' => $userId],
            ['role' => 'viewer', 'kicked_at' => now(), 'is_moderator' => false]
        );

        $liveKit->removeParticipant($live, $target);

        return response()->json(['success' => true]);
    }

    public function participants(Request $request, int $id)
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);
        if (! $this->canModerate($actor, $live)) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $rows = $live->participants()
            ->with('user:id,nome,apelido,email,path_img_avatar')
            ->whereNull('kicked_at')
            ->orderByDesc('joined_at')
            ->get()
            ->map(fn (LiveParticipant $p) => [
                'user_id' => $p->user_id,
                'role' => $p->role,
                'is_moderator' => (bool) $p->is_moderator,
                'chat_muted' => $p->chat_muted,
                'joined_at' => $p->joined_at?->toIso8601String(),
                'nome' => $p->user?->apelido ?: $p->user?->nome,
                'avatar' => $p->user?->path_img_avatar,
            ]);

        return response()->json(['data' => $rows]);
    }

    private function canModerate(User $actor, Live $live): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        return LiveParticipant::query()
            ->where('live_id', $live->id)
            ->where('user_id', $actor->id)
            ->where('is_moderator', true)
            ->whereNull('kicked_at')
            ->exists();
    }
}

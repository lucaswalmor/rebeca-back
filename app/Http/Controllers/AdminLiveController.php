<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLiveRequest;
use App\Jobs\NotifySubscribersOfLive;
use App\Models\Live;
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
            ->with('invites')
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

        $live = DB::transaction(function () use ($request, $data, $isPrivate, $inviteIds) {
            $live = Live::create([
                'admin_id' => $request->user()->id,
                'titulo' => $data['titulo'],
                'descricao' => $data['descricao'] ?? null,
                'starts_at' => $data['starts_at'],
                'is_private' => $isPrivate,
                'price_credits' => (int) ($data['price_credits'] ?? 0),
                'max_participants' => (int) $data['max_participants'],
                'status' => Live::STATUS_AGENDADA,
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

            return $live->load('invites');
        });

        if ($request->boolean('notify', true)) {
            dispatch(new NotifySubscribersOfLive($live->id));
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

        dispatch(new NotifySubscribersOfLive($live->id));

        return response()->json([
            'success' => true,
            'message' => 'Notificações enfileiradas.',
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
            'data' => $live->fresh('invites')->toApiArray($request->user()),
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
            'data' => $live->fresh('invites')->toApiArray($request->user()),
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
            'data' => $live->fresh('invites')->toApiArray($request->user()),
        ]);
    }

    public function muteChatUser(Request $request, int $id, int $userId)
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);
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
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);
        $target = User::query()->findOrFail($userId);

        if ($target->isAdmin()) {
            return response()->json(['message' => 'Não é possível expulsar a host.'], 422);
        }

        LiveParticipant::query()->updateOrCreate(
            ['live_id' => $live->id, 'user_id' => $userId],
            ['role' => 'viewer', 'kicked_at' => now()]
        );

        $liveKit->removeParticipant($live, $target);

        return response()->json(['success' => true]);
    }

    public function participants(Request $request, int $id)
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $live = Live::query()->findOrFail($id);

        $rows = $live->participants()
            ->with('user:id,nome,apelido,email,path_img_avatar')
            ->whereNull('kicked_at')
            ->orderByDesc('joined_at')
            ->get()
            ->map(fn (LiveParticipant $p) => [
                'user_id' => $p->user_id,
                'role' => $p->role,
                'chat_muted' => $p->chat_muted,
                'joined_at' => $p->joined_at?->toIso8601String(),
                'nome' => $p->user?->apelido ?: $p->user?->nome,
                'avatar' => $p->user?->path_img_avatar,
            ]);

        return response()->json(['data' => $rows]);
    }
}

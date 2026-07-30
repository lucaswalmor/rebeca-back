<?php

namespace App\Http\Controllers;

use App\Events\ConversationUpdated;
use App\Events\MessageDeleted;
use App\Events\MessageLiked;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Mail\ChatMessageReceivedMail;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageLike;
use App\Models\User;
use App\Services\ChatLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    public function heartbeat(Request $request)
    {
        $user = $request->user();
        $user->forceFill(['last_seen_at' => now()])->save();

        return response()->json([
            'success' => true,
            'last_seen_at' => $user->last_seen_at?->toIso8601String(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            $count = Conversation::query()
                ->where('admin_id', $user->id)
                ->with('messages')
                ->get()
                ->sum(fn (Conversation $c) => $c->unreadCountFor($user));
        } else {
            $conversation = Conversation::query()
                ->where('subscriber_id', $user->id)
                ->first();

            $count = $conversation ? $conversation->unreadCountFor($user) : 0;
        }

        return response()->json([
            'unread_count' => (int) $count,
            'media_credits' => (int) $user->chat_media_credits,
            'can_access_chat' => $user->isAdmin() || $user->hasAssinaturaAprovadaAtiva(),
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode listar conversas.'], 403);
        }

        $conversations = Conversation::query()
            ->where('admin_id', $user->id)
            ->where(function ($q) {
                // Esconde conversas excluídas "só para mim" até chegar mensagem nova
                $q->whereNull('admin_cleared_at')
                    ->orWhereColumn('last_message_at', '>', 'admin_cleared_at');
            })
            ->whereNotNull('last_message_at')
            ->with(['subscriber', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        return ConversationResource::collection($conversations);
    }

    public function openOrCreate(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return response()->json(['message' => 'Admin deve abrir uma conversa específica.'], 422);
        }

        if (! $user->hasAssinaturaAprovadaAtiva()) {
            return response()->json([
                'message' => 'Sua assinatura não está ativa. Renove para acessar o chat.',
                'requires_subscription' => true,
            ], 403);
        }

        $admin = ChatLogger::adminUser();

        if (! $admin) {
            return response()->json(['message' => 'Administradora não encontrada.'], 404);
        }

        $conversation = Conversation::query()->firstOrCreate(
            [
                'admin_id' => $admin->id,
                'subscriber_id' => $user->id,
            ]
        );

        $conversation->load(['admin', 'subscriber', 'latestMessage']);

        ChatLogger::info('Conversation opened', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        return new ConversationResource($conversation);
    }

    public function startWithSubscriber(Request $request)
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode iniciar conversas.'], 403);
        }

        $request->validate([
            'subscriber_id' => 'required|integer|exists:users,id',
        ]);

        $subscriber = User::query()->findOrFail($request->integer('subscriber_id'));

        if ($subscriber->isAdmin()) {
            return response()->json(['message' => 'Não é possível iniciar conversa com outra administradora.'], 422);
        }

        $conversation = Conversation::query()->firstOrCreate(
            [
                'admin_id' => $user->id,
                'subscriber_id' => $subscriber->id,
            ]
        );

        $conversation->load(['admin', 'subscriber', 'latestMessage']);

        ChatLogger::info('Admin started conversation', [
            'conversation_id' => $conversation->id,
            'subscriber_id' => $subscriber->id,
        ]);

        return new ConversationResource($conversation);
    }

    public function searchableUsers(Request $request)
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode listar usuários.'], 403);
        }

        $search = trim((string) $request->query('search', ''));
        $perPage = min(max((int) $request->query('per_page', 20), 1), 50);

        $query = User::query()
            ->where('is_admin', false)
            ->orderBy('apelido')
            ->orderBy('nome');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('nome', 'like', "%{$search}%")
                    ->orWhere('sobrenome', 'like', "%{$search}%")
                    ->orWhere('apelido', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $existingSubscriberIds = Conversation::query()
            ->where('admin_id', $user->id)
            ->pluck('subscriber_id')
            ->all();

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(function (User $subscriber) use ($existingSubscriberIds) {
            return [
                'id' => $subscriber->id,
                'nome' => $subscriber->nome,
                'sobrenome' => $subscriber->sobrenome,
                'apelido' => $subscriber->apelido,
                'email' => $subscriber->email,
                'path_img_avatar' => $subscriber->path_img_avatar,
                'has_active_subscription' => $subscriber->hasAssinaturaAprovadaAtiva(),
                'has_conversation' => in_array($subscriber->id, $existingSubscriberIds, true),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user();
        $conversation = $this->findAuthorizedConversation($user, $id);

        if (! $user->isAdmin() && ! $user->hasAssinaturaAprovadaAtiva()) {
            return response()->json([
                'message' => 'Sua assinatura não está ativa. Renove para acessar o chat.',
                'requires_subscription' => true,
            ], 403);
        }

        $conversation->load(['admin', 'subscriber', 'latestMessage']);

        return new ConversationResource($conversation);
    }

    public function messages(Request $request, int $id)
    {
        $user = $request->user();
        $conversation = $this->findAuthorizedConversation($user, $id);

        if (! $user->isAdmin() && ! $user->hasAssinaturaAprovadaAtiva()) {
            return response()->json([
                'message' => 'Sua assinatura não está ativa. Renove para acessar o chat.',
                'requires_subscription' => true,
            ], 403);
        }

        $messages = $conversation->messages()
            ->with(['user', 'replyTo.user', 'likes'])
            ->when(
                $conversation->clearedAtFor($user),
                fn ($q) => $q->where('created_at', '>', $conversation->clearedAtFor($user))
            )
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(50);

        return MessageResource::collection($messages);
    }

    public function gallery(Request $request, int $id)
    {
        $user = $request->user();
        $conversation = $this->findAuthorizedConversation($user, $id);

        if (! $user->isAdmin() && ! $user->hasAssinaturaAprovadaAtiva()) {
            return response()->json([
                'message' => 'Sua assinatura não está ativa. Renove para acessar a galeria.',
                'requires_subscription' => true,
            ], 403);
        }

        $media = $conversation->messages()
            ->with(['user', 'likes'])
            ->whereIn('type', ['image', 'video'])
            ->when(
                $conversation->clearedAtFor($user),
                fn ($q) => $q->where('created_at', '>', $conversation->clearedAtFor($user))
            )
            ->orderByDesc('created_at')
            ->get();

        return MessageResource::collection($media);
    }

    public function clear(Request $request, int $id)
    {
        $user = $request->user();
        $conversation = $this->findAuthorizedConversation($user, $id);

        if (! $user->isAdmin() && ! $user->hasAssinaturaAprovadaAtiva()) {
            return response()->json([
                'message' => 'Sua assinatura não está ativa.',
                'requires_subscription' => true,
            ], 403);
        }

        $request->validate([
            'scope' => 'required|string|in:me,everyone',
        ]);

        $scope = $request->input('scope');

        if ($scope === 'everyone' && ! $user->isAdmin()) {
            return response()->json([
                'message' => 'Apenas a administradora pode limpar a conversa para todos.',
            ], 403);
        }

        if ($scope === 'me') {
            if ($user->isAdmin()) {
                $conversation->update(['admin_cleared_at' => now()]);
            } else {
                $conversation->update(['subscriber_cleared_at' => now()]);
            }

            ChatLogger::info('Conversation cleared for me', [
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'scope' => 'me',
                'conversation_id' => $conversation->id,
            ]);
        }

        // Limpar para todos: remove mensagens e arquivos
        DB::transaction(function () use ($conversation) {
            $messages = $conversation->messages()->get();

            foreach ($messages as $message) {
                if ($message->media_path) {
                    try {
                        Storage::disk('s3')->delete($message->media_path);
                    } catch (\Throwable $e) {
                        ChatLogger::error('Failed to delete media on clear everyone', [
                            'path' => $message->media_path,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                $message->likes()->delete();
            }

            $conversation->messages()->delete();

            $conversation->update([
                'admin_cleared_at' => null,
                'subscriber_cleared_at' => null,
                'last_message_at' => null,
            ]);
        });

        broadcast(new ConversationUpdated(
            $conversation->id,
            $conversation->admin_id,
            $conversation->subscriber_id,
            [
                'cleared_for_everyone' => true,
                'cleared_by' => $user->id,
            ]
        ));

        ChatLogger::info('Conversation cleared for everyone', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'scope' => 'everyone',
            'conversation_id' => $conversation->id,
        ]);
    }

    public function store(Request $request, int $id)
    {
        $user = $request->user();
        $conversation = $this->findAuthorizedConversation($user, $id);

        if (! $user->isAdmin() && ! $user->hasAssinaturaAprovadaAtiva()) {
            return response()->json([
                'message' => 'Sua assinatura não está ativa. Renove para conversar.',
                'requires_subscription' => true,
            ], 403);
        }

        $request->validate([
            'type' => 'required|in:text,image,video,audio',
            'body' => 'nullable|string|max:5000',
            'reply_to_id' => 'nullable|integer|exists:messages,id',
            'media' => 'nullable|file|mimes:jpeg,jpg,png,gif,webp,mp4,webm,mov,mp3,ogg,m4a,mpeg,wav,aac,mpga',
        ]);

        $type = $request->input('type');
        $maxAudioSeconds = (int) config('chat.audio_max_seconds', 60);

        if ($type === 'text' && blank($request->input('body'))) {
            throw ValidationException::withMessages(['body' => 'A mensagem de texto é obrigatória.']);
        }

        if (in_array($type, ['image', 'video', 'audio'], true) && ! $request->hasFile('media')) {
            throw ValidationException::withMessages(['media' => 'Arquivo de mídia é obrigatório.']);
        }

        if ($type === 'audio') {
            $duration = (int) $request->input('body', 0);
            if ($duration < 1 || $duration > $maxAudioSeconds) {
                throw ValidationException::withMessages([
                    'body' => "Áudio inválido. Duração máxima: {$maxAudioSeconds} segundos.",
                ]);
            }
        }

        if (! $user->isAdmin() && in_array($type, ['image', 'video'], true)) {
            if ((int) $user->chat_media_credits < 1) {
                return response()->json([
                    'message' => 'Você não tem créditos de mídia. Libere um pacote para enviar fotos/vídeos.',
                    'requires_media_pack' => true,
                    'package_needed' => 'media',
                    'media_credits' => 0,
                ], 403);
            }

            $file = $request->file('media');
            $maxKb = $type === 'video'
                ? (int) config('chat.subscriber_video_max_kb')
                : (int) config('chat.subscriber_image_max_kb');

            if ($file->getSize() > $maxKb * 1024) {
                $limitLabel = $type === 'video' ? '100MB' : '25MB';

                throw ValidationException::withMessages([
                    'media' => "Arquivo muito grande. Limite para assinantes: {$limitLabel}.",
                ]);
            }
        }

        if (! $user->isAdmin() && $type === 'audio') {
            if ((int) $user->chat_audio_credits < 1) {
                return response()->json([
                    'message' => 'Você não tem créditos de áudio. Libere um pacote para enviar áudios.',
                    'requires_media_pack' => true,
                    'package_needed' => 'audio',
                    'audio_credits' => 0,
                ], 403);
            }

            $file = $request->file('media');
            $maxKb = (int) config('chat.subscriber_audio_max_kb', 10 * 1024);
            if ($file->getSize() > $maxKb * 1024) {
                throw ValidationException::withMessages([
                    'media' => 'Áudio muito grande. Limite: 10MB.',
                ]);
            }
        }

        if ($request->filled('reply_to_id')) {
            $reply = Message::query()
                ->where('id', $request->reply_to_id)
                ->where('conversation_id', $conversation->id)
                ->first();

            if (! $reply) {
                throw ValidationException::withMessages(['reply_to_id' => 'Mensagem de resposta inválida.']);
            }
        }

        $mediaPath = null;
        $mediaUrl = null;

        if ($request->hasFile('media')) {
            $file = $request->file('media');
            $ext = $file->getClientOriginalExtension() ?: ($type === 'audio' ? 'webm' : 'bin');
            $mediaPath = 'chat/'.$conversation->id.'/'.time().'_'.uniqid().'.'.$ext;
            Storage::disk('s3')->put($mediaPath, file_get_contents($file->getRealPath()), 'public');
            $mediaUrl = rtrim((string) env('AWS_URL'), '/').'/'.$mediaPath;
        }

        $message = DB::transaction(function () use ($request, $user, $conversation, $type, $mediaPath, $mediaUrl) {
            if (! $user->isAdmin() && in_array($type, ['image', 'video'], true)) {
                $locked = User::query()->where('id', $user->id)->lockForUpdate()->first();
                if ((int) $locked->chat_media_credits < 1) {
                    throw ValidationException::withMessages([
                        'media' => 'Créditos de mídia insuficientes.',
                    ]);
                }
                $locked->decrement('chat_media_credits');
                $user->chat_media_credits = $locked->chat_media_credits;
            }

            if (! $user->isAdmin() && $type === 'audio') {
                $locked = User::query()->where('id', $user->id)->lockForUpdate()->first();
                if ((int) $locked->chat_audio_credits < 1) {
                    throw ValidationException::withMessages([
                        'media' => 'Créditos de áudio insuficientes.',
                    ]);
                }
                $locked->decrement('chat_audio_credits');
                $user->chat_audio_credits = $locked->chat_audio_credits;
            }

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'type' => $type,
                'body' => $request->input('body'),
                'media_path' => $mediaPath,
                'media_url' => $mediaUrl,
                'reply_to_id' => $request->input('reply_to_id'),
                'delivered_at' => now(),
            ]);

            $conversation->update(['last_message_at' => now()]);

            return $message;
        });

        $message->load(['user', 'replyTo.user', 'likes']);

        broadcast(new MessageSent($message))->toOthers();
        broadcast(new ConversationUpdated(
            $conversation->id,
            $conversation->admin_id,
            $conversation->subscriber_id,
            [
                'unread_bump' => true,
                'sender' => [
                    'id' => $user->id,
                    'nome' => $user->nome,
                    'apelido' => $user->apelido,
                ],
                'latest_message' => [
                    'id' => $message->id,
                    'type' => $message->type,
                    'body' => $message->body,
                    'user_id' => $message->user_id,
                    'conversation_id' => $conversation->id,
                    'created_at' => $message->created_at?->toIso8601String(),
                ],
            ]
        ));

        $this->notifyOfflineRecipient($conversation, $user, $message);

        ChatLogger::info('Message sent', [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'type' => $type,
            'user_id' => $user->id,
        ]);

        $fresh = $user->fresh();

        return (new MessageResource($message))->additional([
            'media_credits' => (int) $fresh->chat_media_credits,
            'audio_credits' => (int) $fresh->chat_audio_credits,
        ]);
    }

    public function update(Request $request, int $messageId)
    {
        $user = $request->user();
        $message = Message::query()->with('conversation')->findOrFail($messageId);

        if (! $message->conversation->isParticipant($user)) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        if ((int) $message->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Você só pode editar suas próprias mensagens.'], 403);
        }

        if ($message->type !== 'text') {
            return response()->json(['message' => 'Apenas mensagens de texto podem ser editadas.'], 422);
        }

        if (! $user->isAdmin() && ! $user->hasAssinaturaAprovadaAtiva()) {
            return response()->json([
                'message' => 'Sua assinatura não está ativa.',
                'requires_subscription' => true,
            ], 403);
        }

        $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message->update([
            'body' => $request->input('body'),
            'edited_at' => now(),
        ]);

        $message->load(['user', 'replyTo.user', 'likes']);
        broadcast(new MessageUpdated($message))->toOthers();

        return new MessageResource($message);
    }

    public function destroy(Request $request, int $messageId)
    {
        $user = $request->user();
        $message = Message::query()->with('conversation')->findOrFail($messageId);

        if (! $message->conversation->isParticipant($user)) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        if ((int) $message->user_id !== (int) $user->id && ! $user->isAdmin()) {
            return response()->json(['message' => 'Você só pode excluir suas próprias mensagens.'], 403);
        }

        $conversationId = $message->conversation_id;
        $conversation = $message->conversation;

        if ($message->media_path) {
            try {
                Storage::disk('s3')->delete($message->media_path);
            } catch (\Throwable $e) {
                ChatLogger::error('Failed to delete media from S3', [
                    'path' => $message->media_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $messageIdDeleted = $message->id;
        $message->likes()->delete();
        $message->delete();

        broadcast(new MessageDeleted($conversationId, $messageIdDeleted))->toOthers();
        broadcast(new ConversationUpdated(
            $conversation->id,
            $conversation->admin_id,
            $conversation->subscriber_id,
            ['message_deleted' => $messageIdDeleted]
        ));

        return response()->json(['success' => true]);
    }

    public function toggleLike(Request $request, int $messageId)
    {
        $user = $request->user();
        $message = Message::query()->with('conversation')->findOrFail($messageId);

        if (! $message->conversation->isParticipant($user)) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        if (! $user->isAdmin() && ! $user->hasAssinaturaAprovadaAtiva()) {
            return response()->json([
                'message' => 'Sua assinatura não está ativa.',
                'requires_subscription' => true,
            ], 403);
        }

        $existing = MessageLike::query()
            ->where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            MessageLike::create([
                'message_id' => $message->id,
                'user_id' => $user->id,
            ]);
        }

        $message->load(['user', 'replyTo.user', 'likes']);
        broadcast(new MessageLiked($message))->toOthers();

        return new MessageResource($message);
    }

    public function markRead(Request $request, int $id)
    {
        $user = $request->user();
        $conversation = $this->findAuthorizedConversation($user, $id);

        $now = now();

        if ($user->isAdmin()) {
            $conversation->update(['admin_last_read_at' => $now]);
        } else {
            $conversation->update(['subscriber_last_read_at' => $now]);
        }

        Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => $now, 'delivered_at' => DB::raw('COALESCE(delivered_at, NOW())')]);

        broadcast(new ConversationUpdated(
            $conversation->id,
            $conversation->admin_id,
            $conversation->subscriber_id,
            ['read_by' => $user->id, 'read_at' => $now->toIso8601String()]
        ));

        return response()->json(['success' => true]);
    }

    public function mediaPackageInfo(Request $request)
    {
        $user = $request->user();
        $admin = ChatLogger::adminUser();

        return response()->json([
            'media_credits' => (int) $user->chat_media_credits,
            'audio_credits' => (int) $user->chat_audio_credits,
            'credits_per_pack' => (int) config('chat.media_credits_per_pack', 5),
            'audio_credits_per_pack' => (int) config('chat.audio_credits_per_pack', 5),
            'audio_max_seconds' => (int) config('chat.audio_max_seconds', 60),
            'price' => $admin?->valor_pacote_midia_chat,
            'audio_price' => $admin?->valor_pacote_audio_chat,
            'can_access_chat' => $user->isAdmin() || $user->hasAssinaturaAprovadaAtiva(),
            'wallpaper_desktop' => $admin?->chat_wallpaper_desktop,
            'wallpaper_mobile' => $admin?->chat_wallpaper_mobile,
        ]);
    }

    private function findAuthorizedConversation(User $user, int $id): Conversation
    {
        $conversation = Conversation::query()->findOrFail($id);

        if (! $conversation->isParticipant($user)) {
            abort(403, 'Não autorizado.');
        }

        return $conversation;
    }

    private function notifyOfflineRecipient(Conversation $conversation, User $sender, Message $message): void
    {
        $recipient = $conversation->otherParty($sender)?->fresh();

        if (! $recipient) {
            return;
        }

        if (ChatLogger::isOnline($recipient)) {
            ChatLogger::info('Recipient online, skip email', [
                'recipient_id' => $recipient->id,
            ]);

            return;
        }

        $email = $recipient->isAdmin()
            ? (string) config('chat.admin_email', 'becalima007@icloud.com')
            : $recipient->email;

        try {
            Mail::to($email)->send(new ChatMessageReceivedMail($recipient, $sender, $message));
            ChatLogger::info('Offline email sent', [
                'to' => $email,
                'message_id' => $message->id,
            ]);
        } catch (\Throwable $e) {
            ChatLogger::error('Failed to send offline email', [
                'to' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

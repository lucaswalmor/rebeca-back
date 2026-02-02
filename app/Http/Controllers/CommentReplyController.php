<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommentReplyRequest;
use App\Models\Comment;
use App\Models\CommentReply;

class CommentReplyController extends Controller
{
    /**
     * Store a newly created reply.
     */
    public function store(StoreCommentReplyRequest $request, string $commentId)
    {
        $comment = Comment::findOrFail($commentId);

        // Verificar se já existe uma resposta para este comentário
        if ($comment->reply) {
            return response()->json([
                'message' => 'Este comentário já possui uma resposta.',
            ], 422);
        }

        $reply = CommentReply::create([
            'comment_id' => $commentId,
            'user_id' => $request->user()->id,
            'reply' => $request->validated()['reply'],
        ]);

        $reply->load(['user']);

        $replyData = [
            'id' => $reply->id,
            'name' => $reply->user->apelido ?: 'usuario-'.$reply->user_id,
            'comment' => $reply->reply,
            'createdAt' => $reply->created_at->toISOString(),
            'timeAgo' => $reply->time_ago,
            'user_id' => $reply->user_id,
        ];

        return response()->json([
            'message' => 'Resposta criada com sucesso.',
            'data' => $replyData,
        ], 201);
    }

    /**
     * Remove the specified reply.
     */
    public function destroy(string $id)
    {
        $reply = CommentReply::findOrFail($id);
        $user = auth()->user();

        if (! $user->isAdmin() && $reply->user_id !== $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para deletar esta resposta.',
            ], 403);
        }

        $reply->delete();

        return response()->json([
            'message' => 'Resposta deletada com sucesso.',
        ]);
    }
}

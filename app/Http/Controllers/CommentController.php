<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommentRequest;
use App\Models\Comment;
use App\Models\Post;

class CommentController extends Controller
{
    /**
     * Display a listing of comments for a post.
     */
    public function index(string $postId)
    {
        $post = Post::findOrFail($postId);
        $comments = Comment::with(['user', 'reply.user'])
            ->where('post_id', $postId)
            ->orderBy('created_at', 'desc')
            ->get();

        $comments = $comments->map(function ($comment) {
            $commentData = $comment->toArray();
            $commentData['avatar'] = $comment->user->path_img_avatar ?? 'https://primefaces.org/cdn/primevue/images/avatar/amyelsner.png';
            $commentData['name'] = $comment->user->apelido ?: 'usuario-'.$comment->user_id;
            $commentData['timeAgo'] = $comment->time_ago;
            $commentData['user_id'] = $comment->user_id;
            if ($comment->reply) {
                $commentData['reply'] = [
                    'id' => $comment->reply->id,
                    'name' => $comment->reply->user->apelido ?: 'usuario-'.$comment->reply->user_id,
                    'comment' => $comment->reply->reply,
                    'createdAt' => $comment->reply->created_at->toISOString(),
                    'timeAgo' => $comment->reply->time_ago,
                    'user_id' => $comment->reply->user_id,
                ];
            }

            return $commentData;
        });

        return response()->json([
            'data' => $comments,
        ]);
    }

    /**
     * Store a newly created comment.
     */
    public function store(StoreCommentRequest $request, string $postId)
    {
        $post = Post::findOrFail($postId);

        $comment = Comment::create([
            'post_id' => $postId,
            'user_id' => $request->user()->id,
            'comment' => $request->validated()['comment'],
        ]);

        $comment->load(['user', 'reply.user']);

        $commentData = $comment->toArray();
        $commentData['avatar'] = $comment->user->path_img_avatar ?? 'https://primefaces.org/cdn/primevue/images/avatar/amyelsner.png';
        $commentData['name'] = $comment->user->apelido ?: 'usuario-'.$comment->user_id;
        $commentData['timeAgo'] = $comment->time_ago;
        $commentData['user_id'] = $comment->user_id;

        return response()->json([
            'message' => 'Comentário criado com sucesso.',
            'data' => $commentData,
        ], 201);
    }

    /**
     * Remove the specified comment.
     */
    public function destroy(string $id)
    {
        $comment = Comment::findOrFail($id);
        $user = auth()->user();

        if (! $user->isAdmin() && $comment->user_id !== $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para deletar este comentário.',
            ], 403);
        }

        $comment->delete();

        return response()->json([
            'message' => 'Comentário deletado com sucesso.',
        ]);
    }
}

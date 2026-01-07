<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostLike;
use Illuminate\Http\Request;

class PostLikeController extends Controller
{
    /**
     * Toggle like/deslike do post.
     */
    public function toggle(Request $request, string $postId)
    {
        $post = Post::findOrFail($postId);
        $user = $request->user();

        $like = PostLike::where('post_id', $postId)
            ->where('user_id', $user->id)
            ->first();

        if ($like) {
            $like->delete();
            $liked = false;
        } else {
            PostLike::create([
                'post_id' => $postId,
                'user_id' => $user->id,
            ]);
            $liked = true;
        }

        return response()->json([
            'message' => $liked ? 'Post curtido com sucesso.' : 'Post descurtido com sucesso.',
            'liked' => $liked,
            'likes_count' => $post->fresh()->likes_count,
        ]);
    }
}

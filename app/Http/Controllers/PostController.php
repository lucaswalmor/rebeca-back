<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PostController extends Controller
{
    /**
     * Display a listing of the resource (apenas posts ativos).
     */
    public function index(Request $request)
    {
        // Tentar obter o usuário autenticado (para verificar likes)
        $user = $request->user();
        
        // Se não encontrou, tenta pelo Auth (funciona mesmo em rotas públicas se houver token)
        if (! $user && $request->bearerToken()) {
            $user = Auth::guard('sanctum')->user();
        }
        
        $tipoPost = $request->query('tipo_post'); // 1=simples, 2=exclusivo
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $query = Post::with(['user', 'media', 'likes', 'comments.reply.user'])
            ->where('status', 'ativo');

        if ($tipoPost) {
            $query->where('tipo_post', $tipoPost);
        }

        $total = $query->count();
        $posts = $query->orderBy('is_fixed', 'desc')
            ->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $posts = $posts->map(function ($post) use ($user) {
            $postData = $post->toArray();
            $postData['isLiked'] = $user ? $post->is_liked : false;
            $postData['likes'] = $post->likes_count;
            $postData['comments_count'] = $post->comments->count();
            $postData['media'] = $post->media->map(function ($media) {
                return [
                    'url' => env('AWS_URL') . '/' . $media->path,
                    'tipo' => $media->tipo
                ];
            })->toArray();
            // Manter compatibilidade com código antigo
            $postData['image'] = $post->media->map(function ($media) {
                return env('AWS_URL') . '/' . $media->path;
            })->toArray();
            $postData['date'] = $post->created_at->format('d/m/Y');
            $postData['status'] = $post->status;
            $postData['is_fixed'] = $post->is_fixed;
            // Adicionar avatar e nome do usuárioa
            if ($post->user) {
                $postData['user_avatar'] = $post->user->path_img_avatar ?? 'https://primefaces.org/cdn/primevue/images/avatar/amyelsner.png';
                $postData['user_name'] = $post->user->nome.' '.$post->user->sobrenome;
                $postData['user_apelido'] = $post->user->apelido;
            }
            $postData['comments'] = $post->comments->map(function ($comment) {
                $commentData = $comment->toArray();
                $commentData['name'] = $comment->user->apelido;
                $commentData['timeAgo'] = $comment->time_ago;
                $commentData['user_id'] = $comment->user_id;
                if ($comment->reply) {
                    $commentData['reply'] = [
                        'id' => $comment->reply->id,
                        'name' => $comment->reply->user->nome.' '.$comment->reply->user->sobrenome,
                        'comment' => $comment->reply->reply,
                        'createdAt' => $comment->reply->created_at->toISOString(),
                        'timeAgo' => $comment->reply->time_ago,
                        'user_id' => $comment->reply->user_id,
                    ];  
                }

                return $commentData;
            });

            return $postData;
        });

        return response()->json([
            'data' => $posts,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
                'has_more' => ($page * $perPage) < $total
            ]
        ]);
    }

    /**
     * Display all posts (ativos e inativos) - apenas para admins.
     */
    public function indexAdmin(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'message' => 'Acesso negado. Apenas administradores podem acessar esta rota.',
            ], 403);
        }

        $tipoPost = $request->query('tipo_post'); // 1=simples, 2=exclusivo
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $query = Post::with(['user', 'media', 'likes', 'comments.reply.user']);

        if ($tipoPost) {
            $query->where('tipo_post', $tipoPost);
        }

        $total = $query->count();
        $posts = $query->orderBy('is_fixed', 'desc')
            ->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $posts = $posts->map(function ($post) use ($user) {
            $postData = $post->toArray();
            $postData['isLiked'] = $user ? $post->is_liked : false;
            $postData['likes'] = $post->likes_count;
            $postData['comments_count'] = $post->comments->count();
            $postData['media'] = $post->media->map(function ($media) {
                return [
                    'url' => env('AWS_URL') . '/' . $media->path,
                    'tipo' => $media->tipo
                ];
            })->toArray();
            // Manter compatibilidade com código antigo
            $postData['image'] = $post->media->map(function ($media) {
                return env('AWS_URL') . '/' . $media->path;
            })->toArray();
            $postData['date'] = $post->created_at->format('d/m/Y');
            $postData['status'] = $post->status;
            $postData['is_fixed'] = $post->is_fixed;
            // Adicionar avatar e nome do usuário
            if ($post->user) {
                $postData['user_avatar'] = $post->user->path_img_avatar ?? 'https://primefaces.org/cdn/primevue/images/avatar/amyelsner.png';
                $postData['user_name'] = $post->user->nome.' '.$post->user->sobrenome;
                $postData['user_apelido'] = $post->user->apelido;
            }
            $postData['comments'] = $post->comments->map(function ($comment) {
                $commentData = $comment->toArray();
                $commentData['avatar'] = $comment->user->path_img_avatar ?? 'https://primefaces.org/cdn/primevue/images/avatar/amyelsner.png';
                $commentData['name'] = $comment->user->nome.' '.$comment->user->sobrenome;
                $commentData['timeAgo'] = $comment->time_ago;
                $commentData['user_id'] = $comment->user_id;
                if ($comment->reply) {
                    $commentData['reply'] = [
                        'id' => $comment->reply->id,
                        'name' => $comment->reply->user->nome.' '.$comment->reply->user->sobrenome,
                        'comment' => $comment->reply->reply,
                        'createdAt' => $comment->reply->created_at->toISOString(),
                        'timeAgo' => $comment->reply->time_ago,
                        'user_id' => $comment->reply->user_id,
                    ];
                }

                return $commentData;
            });

            return $postData;
        });

        return response()->json([
            'data' => $posts,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
                'has_more' => ($page * $perPage) < $total
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePostRequest $request)
    {
        $validated = $request->validated();
        $validated['user_id'] = $request->user()->id;
        $validated['status'] = $validated['status'] ?? 'ativo';
        $validated['is_fixed'] = false;
        $post = Post::create($validated);

        return response()->json([
            'message' => 'Post criado com sucesso.',
            'data' => $post,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id, Request $request)
    {
        $user = $request->user();
        $post = Post::with(['user', 'media', 'likes', 'comments.reply.user'])
            ->findOrFail($id);

        $postData = $post->toArray();
        $postData['isLiked'] = $user ? $post->is_liked : false;
        $postData['likes'] = $post->likes_count;
        $postData['comments_count'] = $post->comments->count();
        $postData['image'] = $post->media->map(function ($media) {
            return env('AWS_URL') . '/' . $media->path;
        })->toArray();
        $postData['date'] = $post->created_at->format('d/m/Y');
        $postData['status'] = $post->status;
        $postData['is_fixed'] = $post->is_fixed;
        $postData['comments'] = $post->comments->map(function ($comment) {
            $commentData = $comment->toArray();
            $commentData['avatar'] = $comment->user->path_img_avatar ?? 'https://primefaces.org/cdn/primevue/images/avatar/amyelsner.png';
            $commentData['name'] = $comment->user->nome.' '.$comment->user->sobrenome;
            $commentData['timeAgo'] = $comment->time_ago;
            $commentData['user_id'] = $comment->user_id;
            if ($comment->reply) {
                $commentData['reply'] = [
                    'id' => $comment->reply->id,
                    'name' => $comment->reply->user->nome.' '.$comment->reply->user->sobrenome,
                    'comment' => $comment->reply->reply,
                    'createdAt' => $comment->reply->created_at->toISOString(),
                    'timeAgo' => $comment->reply->time_ago,
                    'user_id' => $comment->reply->user_id,
                ];
            }

            return $commentData;
        });

        return response()->json([
            'data' => $postData,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePostRequest $request, string $id)
    {
        $post = Post::findOrFail($id);

        $validated = $request->validated();

        $post->update($validated);

        return response()->json([
            'message' => 'Post atualizado com sucesso.',
            'data' => $post->fresh(),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id, Request $request)
    {
        $post = Post::findOrFail($id);
        $user = $request->user();

        if (! $user->isAdmin() && $post->user_id !== $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para deletar este post.',
            ], 403);
        }

        // Deletar mídias do R2
        foreach ($post->media as $media) {
            $oldPath = str_replace('/rebeca/', '/', $media->path);
            $oldPath = parse_url($oldPath, PHP_URL_PATH);
            $oldPath = ltrim($oldPath, '/');
            if (strpos($oldPath, 'rebeca/') === 0) {
                $oldPath = substr($oldPath, 7);
            }
            Storage::disk('s3')->delete($oldPath);
        }

        $post->delete();

        return response()->json([
            'message' => 'Post deletado com sucesso.',
        ]);
    }

    /**
     * Upload de mídia para o post
     */
    public function uploadMedia(Request $request, string $id)
    {
        $post = Post::findOrFail($id);
        $user = Auth::user();

        Log::info('post_media_upload_started', [
            'post_id' => $post->id,
            'user_id' => $user?->id,
            'files_count' => count($request->file('media', [])),
        ]);

        if (! $user->isAdmin() && $post->user_id !== $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para adicionar mídia a este post.',
            ], 403);
        }

        $request->validate([
            'media.*' => 'required|file|mimes:jpeg,jpg,png,gif,webp,mp4,webm,mov|max:512000', // 500MB max
        ]);

        $uploadedMedia = [];
        $ordem = $post->media()->max('ordem') ?? 0;

        foreach ($request->file('media') as $file) {
            $ordem++;
            $extension = $file->getClientOriginalExtension();
            $tipo = in_array($extension, ['mp4', 'webm', 'mov']) ? 'video' : 'image';
            $path = "posts/{$post->id}/media/".time().'_'.$ordem.'_'.$file->getClientOriginalName();
            Log::info('post_media_upload_file_started', [
                'post_id' => $post->id,
                'path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'disk' => 's3',
                'bucket' => config('filesystems.disks.s3.bucket'),
                'endpoint' => config('filesystems.disks.s3.endpoint'),
            ]);

            try {
                $uploaded = Storage::disk('s3')->put($path, file_get_contents($file), 'public');
            } catch (\Throwable $e) {
                Log::error('post_media_upload_exception', [
                    'post_id' => $post->id,
                    'path' => $path,
                    'message' => $e->getMessage(),
                ]);

                throw $e;
            }

            Log::info('post_media_upload_file_result', [
                'post_id' => $post->id,
                'path' => $path,
                'uploaded' => $uploaded,
            ]);

            // Salvar apenas o path relativo no banco (sem URL completa)
            $media = PostMedia::create([
                'post_id' => $post->id,
                'path' => $path,
                'tipo' => $tipo,
                'ordem' => $ordem,
            ]);

            // Construir URL completa apenas para retornar na resposta
            $publicUrl = config('filesystems.disks.s3.url');
            $bucket = config('filesystems.disks.s3.bucket');

            if ($publicUrl) {
                if (strpos($publicUrl, 'r2.dev') !== false) {
                    $url = rtrim($publicUrl, '/').'/'.$bucket.'/'.$path;
                } else {
                    $url = rtrim($publicUrl, '/').'/'.$path;
                }
            } else {
                $endpoint = config('filesystems.disks.s3.endpoint');
                $url = rtrim($endpoint, '/').'/'.$bucket.'/'.$path;
            }

            $uploadedMedia[] = [
                'id' => $media->id,
                'url' => $url,
                'tipo' => $tipo,
            ];
        }

        Log::info('post_media_upload_finished', [
            'post_id' => $post->id,
            'uploaded_count' => count($uploadedMedia),
        ]);

        return response()->json([
            'message' => 'Mídia enviada com sucesso.',
            'data' => $uploadedMedia,
        ]);
    }

    /**
     * Fixar/desfixar post
     */
    public function toggleFixed(string $id)
    {
        $post = Post::findOrFail($id);
        $user = request()->user();

        if (! $user->isAdmin() && $post->user_id !== $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para fixar este post.',
            ], 403);
        }

        // Se está tentando fixar, verificar se já existem 3 posts fixos do mesmo tipo
        if (! $post->is_fixed) {
            $fixedCount = Post::where('user_id', $post->user_id)
                ->where('tipo_post', $post->tipo_post)
                ->where('is_fixed', true)
                ->where('id', '!=', $post->id)
                ->count();

            if ($fixedCount >= 3) {
                $tipoNome = $post->tipo_post == 1 ? 'simples' : 'exclusivos';
                return response()->json([
                    'message' => "Você já possui 3 posts {$tipoNome} fixados. Desfixe um post antes de fixar outro.",
                ], 422);
            }
        }

        $post->is_fixed = ! $post->is_fixed;
        $post->save();

        return response()->json([
            'message' => $post->is_fixed ? 'Post fixado com sucesso.' : 'Post desfixado com sucesso.',
            'data' => $post->fresh(),
        ]);
    }

    /**
     * Ativar/inativar post
     */
    public function toggleStatus(string $id)
    {
        $post = Post::findOrFail($id);
        $user = request()->user();

        if (! $user->isAdmin() && $post->user_id !== $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para alterar o status deste post.',
            ], 403);
        }

        $post->status = $post->status === 'ativo' ? 'inativo' : 'ativo';
        $post->save();

        return response()->json([
            'message' => $post->status === 'ativo' ? 'Post ativado com sucesso.' : 'Post inativado com sucesso.',
            'data' => $post->fresh(),
        ]);
    }

    /**
     * Get the like status for a specific post
     */
    public function getLikeStatus(string $id, Request $request)
    {
        $post = Post::findOrFail($id);

        // Tentar obter o usuário autenticado
        $user = $request->user();

        // Se não encontrou, tenta pelo Auth guard
        if (! $user && $request->bearerToken()) {
            $user = \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
        }

        return response()->json([
            'isLiked' => $user ? $post->is_liked : false,
            'likes_count' => $post->likes_count,
        ]);
    }
}

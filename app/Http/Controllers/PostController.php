<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Models\Post;
use App\Models\PostCompra;
use App\Models\PostMedia;
use App\Models\User;
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
        $user = $this->resolveUser($request);

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $query = Post::with(['user', 'media', 'likes', 'comments.reply.user'])
            ->where('status', 'ativo');

        $total = $query->count();
        $posts = $query->orderBy('is_fixed', 'desc')
            ->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $hasPreviewAccess = $this->userHasPreviewAccess($user);

        $posts = $posts->map(function ($post) use ($user, $hasPreviewAccess) {
            return $this->formatPostData($post, $user, $hasPreviewAccess);
        });

        return response()->json([
            'data' => $posts,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
                'has_more' => ($page * $perPage) < $total,
            ],
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

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $query = Post::with(['user', 'media', 'likes', 'comments.reply.user']);

        $total = $query->count();
        $posts = $query->orderBy('is_fixed', 'desc')
            ->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $posts = $posts->map(function ($post) use ($user) {
            return $this->formatPostData($post, $user, true);
        });

        return response()->json([
            'data' => $posts,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
                'has_more' => ($page * $perPage) < $total,
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePostRequest $request)
    {
        $validated = $request->validated();
        $validated['user_id'] = $request->user()->id;
        $validated['tipo_post'] = 2; // Todo conteúdo é exclusivo para assinantes
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
        $user = $this->resolveUser($request);
        $post = Post::with(['user', 'media', 'likes', 'comments.reply.user'])
            ->findOrFail($id);

        $hasPreviewAccess = $this->userHasPreviewAccess($user);

        return response()->json([
            'data' => $this->formatPostData($post, $user, $hasPreviewAccess),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePostRequest $request, string $id)
    {
        $post = Post::findOrFail($id);

        $validated = $request->validated();
        unset($validated['tipo_post']);

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

        foreach ($post->media as $media) {
            $this->deleteMediaFromStorage($media->path);
        }

        $post->delete();

        return response()->json([
            'message' => 'Post deletado com sucesso.',
        ]);
    }

    /**
     * Upload de mídia para o post.
     * Envie is_preview=1 para cadastrar a prévia pública (apenas 1 arquivo).
     */
    public function uploadMedia(Request $request, string $id)
    {
        $post = Post::findOrFail($id);
        $user = Auth::user();

        Log::info('post_media_upload_started', [
            'post_id' => $post->id,
            'user_id' => $user?->id,
            'files_count' => count($request->file('media', [])),
            'is_preview' => $request->boolean('is_preview'),
        ]);

        if (! $user->isAdmin() && $post->user_id !== $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para adicionar mídia a este post.',
            ], 403);
        }

        $isPreview = $request->boolean('is_preview');

        $request->validate([
            'media' => $isPreview ? 'required|array|max:1' : 'required|array',
            'media.*' => 'required|file|mimes:jpeg,jpg,png,gif,webp,mp4,webm,mov',
        ]);

        if ($isPreview) {
            $existingPreviews = $post->media()->where('is_preview', true)->get();
            foreach ($existingPreviews as $existing) {
                $this->deleteMediaFromStorage($existing->path);
                $existing->delete();
            }
        }

        $uploadedMedia = [];
        $ordem = $isPreview ? 0 : (($post->media()->where('is_preview', false)->max('ordem') ?? 0));

        foreach ($request->file('media') as $file) {
            $ordem++;
            $extension = strtolower($file->getClientOriginalExtension());
            $tipo = in_array($extension, ['mp4', 'webm', 'mov']) ? 'video' : 'image';
            $folder = $isPreview ? 'preview' : 'media';
            $path = "posts/{$post->id}/{$folder}/".time().'_'.$ordem.'_'.$file->getClientOriginalName();

            Log::info('post_media_upload_file_started', [
                'post_id' => $post->id,
                'path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'is_preview' => $isPreview,
            ]);

            try {
                $uploaded = Storage::disk('s3')->put($path, file_get_contents($file), 'public');
                if (! $uploaded) {
                    Log::error('post_media_upload_false', [
                        'post_id' => $post->id,
                        'path' => $path,
                    ]);
                    throw new \Exception('Storage::put retornou false');
                }
            } catch (\Throwable $e) {
                Log::error('post_media_upload_exception', [
                    'post_id' => $post->id,
                    'path' => $path,
                    'message' => $e->getMessage(),
                ]);

                throw $e;
            }

            $media = PostMedia::create([
                'post_id' => $post->id,
                'path' => $path,
                'tipo' => $tipo,
                'ordem' => $isPreview ? 0 : $ordem,
                'is_preview' => $isPreview,
            ]);

            $uploadedMedia[] = [
                'id' => $media->id,
                'url' => $this->buildMediaUrl($path),
                'tipo' => $tipo,
                'is_preview' => $isPreview,
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

        if (! $post->is_fixed) {
            $fixedCount = Post::where('user_id', $post->user_id)
                ->where('is_fixed', true)
                ->where('id', '!=', $post->id)
                ->count();

            if ($fixedCount >= 3) {
                return response()->json([
                    'message' => 'Você já possui 3 posts fixados. Desfixe um post antes de fixar outro.',
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
        $user = $this->resolveUser($request);

        return response()->json([
            'isLiked' => $user ? $post->is_liked : false,
            'likes_count' => $post->likes_count,
        ]);
    }

    private function resolveUser(Request $request): ?User
    {
        $user = $request->user();

        if (! $user && $request->bearerToken()) {
            $user = Auth::guard('sanctum')->user();
        }

        return $user;
    }

    private function userHasPreviewAccess(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasAssinaturaAprovadaAtiva();
    }

    private function userHasPurchasedPost(?User $user, Post $post): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return PostCompra::where('user_id', $user->id)
            ->where('post_id', $post->id)
            ->where('status', 'aprovado')
            ->exists();
    }

    private function formatPostData(Post $post, ?User $user, bool $hasPreviewAccess): array
    {
        $previewMedia = $post->media->firstWhere('is_preview', true);
        $contentMedia = $post->media->where('is_preview', false)->values();
        $preview = $previewMedia ? $this->formatSingleMedia($previewMedia) : null;

        $purchased = $this->userHasPurchasedPost($user, $post);
        $isAdmin = $user && $user->isAdmin();

        // Assinatura ativa = vê prévia; assinatura + compra = vê conteúdo completo
        $hasFullAccess = $isAdmin || ($hasPreviewAccess && $purchased);

        if ($hasFullAccess) {
            $visibleMedia = $this->formatMediaCollection($contentMedia);
        } elseif ($hasPreviewAccess) {
            $visibleMedia = $preview ? [$preview] : [];
        } else {
            // Sem assinatura: não envia URLs de prévia nem de conteúdo
            $visibleMedia = [];
            $preview = null;
        }

        $postData = $post->toArray();
        $postData['isLiked'] = $user ? $post->is_liked : false;
        $postData['likes'] = $post->likes_count;
        $postData['comments_count'] = $post->comments->count();
        $postData['media'] = $visibleMedia;
        $postData['preview'] = $preview;
        $postData['preco'] = (float) $post->preco;
        $postData['media_count'] = $contentMedia->count();
        $postData['purchased'] = $purchased;
        $postData['has_preview_access'] = $hasPreviewAccess || $isAdmin;
        $postData['has_full_access'] = $hasFullAccess;
        $postData['is_locked'] = ! $hasFullAccess;
        $postData['image'] = array_column($visibleMedia, 'url');
        $postData['date'] = $post->created_at->format('d/m/Y');
        $postData['status'] = $post->status;
        $postData['is_fixed'] = $post->is_fixed;

        if ($post->user) {
            $postData['user_avatar'] = $post->user->path_img_avatar ?? 'https://primefaces.org/cdn/primevue/images/avatar/amyelsner.png';
            $postData['user_name'] = $post->user->nome.' '.$post->user->sobrenome;
            $postData['user_apelido'] = $post->user->apelido;
        }

        $postData['comments'] = $post->comments->map(function ($comment) {
            $commentData = $comment->toArray();
            $commentData['avatar'] = $comment->user->path_img_avatar ?? 'https://primefaces.org/cdn/primevue/images/avatar/amyelsner.png';
            $commentData['name'] = $comment->user->apelido ?? ($comment->user->nome.' '.$comment->user->sobrenome);
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
    }

    private function formatMediaCollection($medias): array
    {
        return $medias->map(fn ($media) => $this->formatSingleMedia($media))->values()->toArray();
    }

    private function formatSingleMedia(PostMedia $media): array
    {
        return [
            'url' => $this->buildMediaUrl($media->path),
            'tipo' => $media->tipo,
            'is_preview' => (bool) $media->is_preview,
        ];
    }

    private function buildMediaUrl(string $path): string
    {
        return rtrim((string) env('AWS_URL'), '/').'/'.ltrim($path, '/');
    }

    private function deleteMediaFromStorage(string $path): void
    {
        $oldPath = str_replace('/rebeca/', '/', $path);
        $oldPath = parse_url($oldPath, PHP_URL_PATH) ?: $oldPath;
        $oldPath = ltrim($oldPath, '/');
        if (strpos($oldPath, 'rebeca/') === 0) {
            $oldPath = substr($oldPath, 7);
        }
        Storage::disk('s3')->delete($oldPath);
    }
}

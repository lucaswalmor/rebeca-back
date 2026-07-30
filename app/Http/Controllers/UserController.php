<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::all();

        return response()->json([
            'data' => $users,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user = User::create($validated);

        return response()->json([
            'message' => 'Usuário criado com sucesso.',
            'data' => $user,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::findOrFail($id);

        return response()->json([
            'data' => $user,
        ]);
    }

    /**
     * Buscar usuário por apelido (rota pública)
     */
    public function findByApelido(string $apelido, Request $request)
    {
        $user = User::where('apelido', $apelido)->first();

        if (! $user) {
            return response()->json([
                'message' => 'Usuário não encontrado.',
            ], 404);
        }

        // Tentar obter o usuário autenticado de múltiplas formas
        // Primeiro tenta pelo request (funciona em rotas com middleware)
        $loggedUser = $request->user();

        // Se não encontrou, tenta pelo Auth (funciona mesmo em rotas públicas se houver token)
        if (! $loggedUser && $request->bearerToken()) {
            $loggedUser = Auth::guard('sanctum')->user();
        }

        $isAdmin = $loggedUser && $loggedUser->isAdmin();

        // Contagem total de posts (todo conteúdo é exclusivo para assinantes)
        $totalPosts = \App\Models\Post::where('user_id', $user->id)
            ->when(! $isAdmin, function ($query) {
                $query->where('status', 'ativo');
            })
            ->count();

        $postsCount = [
            'total' => $totalPosts,
        ];

        $userData = $user->toArray();
        $userData['path_img_banner'] = $user->path_img_banner;
        $userData['path_img_avatar'] = $user->path_img_avatar;
        $userData['posts_count'] = $postsCount;

        return response()->json([
            'data' => $userData,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, string $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validated();

        // Hash da senha se fornecida
        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        // Converter valores de assinatura se vierem formatados
        if (isset($validated['valor_assinatura_mensal']) && is_string($validated['valor_assinatura_mensal'])) {
            $validated['valor_assinatura_mensal'] = $this->converterValorFormatado($validated['valor_assinatura_mensal']);
        }

        if (isset($validated['valor_assinatura_trimestral']) && is_string($validated['valor_assinatura_trimestral'])) {
            $validated['valor_assinatura_trimestral'] = $this->converterValorFormatado($validated['valor_assinatura_trimestral']);
        }

        if (isset($validated['valor_assinatura_semestral']) && is_string($validated['valor_assinatura_semestral'])) {
            $validated['valor_assinatura_semestral'] = $this->converterValorFormatado($validated['valor_assinatura_semestral']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'Usuário atualizado com sucesso.',
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json([
            'message' => 'Usuário deletado com sucesso.',
        ]);
    }

    /**
     * Upload de banner do usuário
     */
    public function uploadBanner(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'banner' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120', // 5MB max
        ]);

        // Deletar banner antigo se existir
        if ($user->path_img_banner) {
            // Extrair o path do URL (remover /rebeca/ se existir)
            $oldPath = str_replace('/rebeca/', '/', $user->path_img_banner);
            $oldPath = parse_url($oldPath, PHP_URL_PATH);
            $oldPath = ltrim($oldPath, '/');
            if (strpos($oldPath, 'rebeca/') === 0) {
                $oldPath = substr($oldPath, 7); // Remove 'rebeca/'
            }
            Storage::disk('s3')->delete($oldPath);
        }

        // Upload do novo banner
        $file = $request->file('banner');
        $path = "user/{$user->id}/banner/".time().'_'.$file->getClientOriginalName();
        Storage::disk('s3')->put($path, file_get_contents($file), 'public');

        // Construir URL completa do R2
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

        // Remover /rebeca/ da URL antes de salvar no banco
        $urlToSave = str_replace('/'.$bucket.'/', '/', $url);

        // Atualizar usuário
        $user->update(['path_img_banner' => $urlToSave]);

        return response()->json([
            'message' => 'Banner atualizado com sucesso.',
            'url' => $url,
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Upload de avatar do usuárior
     */
    public function uploadAvatar(Request $request, string $id)
    {
        $auth = $request->user();
        $user = User::findOrFail($id);

        if (! $auth || (! $auth->isAdmin() && (int) $auth->id !== (int) $user->id)) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:2048', // 2MB max
        ]);

        // Deletar avatar antigo se existir
        if ($user->path_img_avatar) {
            // Extrair o path do URL (remover /rebeca/ se existir)
            $oldPath = str_replace('/rebeca/', '/', $user->path_img_avatar);
            $oldPath = parse_url($oldPath, PHP_URL_PATH);
            $oldPath = ltrim($oldPath, '/');
            if (strpos($oldPath, 'rebeca/') === 0) {
                $oldPath = substr($oldPath, 7); // Remove 'rebeca/'
            }
            Storage::disk('s3')->delete($oldPath);
        }

        // Upload do novo avatar
        $file = $request->file('avatar');
        $path = "user/{$user->id}/avatar/".time().'_'.$file->getClientOriginalName();
        Storage::disk('s3')->put($path, file_get_contents($file), 'public');

        // Construir URL completa do R2
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

        // Remover /rebeca/ da URL antes de salvar no banco
        $urlToSave = str_replace('/'.$bucket.'/', '/', $url);

        // Atualizar usuário
        $user->update(['path_img_avatar' => $urlToSave]);

        return response()->json([
            'message' => 'Avatar atualizado com sucesso.',
            'url' => $url,
            'data' => $user->fresh(),
        ]);
    }

    public function uploadChatWallpaper(Request $request, string $id)
    {
        $auth = $request->user();
        $user = User::findOrFail($id);

        if (! $auth || (! $auth->isAdmin() && (int) $auth->id !== (int) $user->id)) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        if (! $user->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode configurar wallpapers do chat.'], 403);
        }

        $request->validate([
            'type' => 'required|string|in:desktop,mobile',
            'wallpaper' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:8192',
        ]);

        $type = $request->input('type');
        $field = $type === 'desktop' ? 'chat_wallpaper_desktop' : 'chat_wallpaper_mobile';

        if ($user->{$field}) {
            $this->deleteS3PathFromUrl($user->{$field});
        }

        $file = $request->file('wallpaper');
        $path = "user/{$user->id}/chat-wallpaper/{$type}/".time().'_'.$file->getClientOriginalName();
        Storage::disk('s3')->put($path, file_get_contents($file), 'public');

        $url = $this->buildPublicS3Url($path);
        $urlToSave = $this->normalizeStoredUrl($url);

        $user->update([$field => $urlToSave]);

        return response()->json([
            'message' => 'Wallpaper do chat atualizado com sucesso.',
            'type' => $type,
            'url' => $urlToSave,
            'data' => $user->fresh(),
        ]);
    }

    public function deleteChatWallpaper(Request $request, string $id, string $type)
    {
        $auth = $request->user();
        $user = User::findOrFail($id);

        if (! $auth || (! $auth->isAdmin() && (int) $auth->id !== (int) $user->id)) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        if (! in_array($type, ['desktop', 'mobile'], true)) {
            return response()->json(['message' => 'Tipo inválido.'], 422);
        }

        $field = $type === 'desktop' ? 'chat_wallpaper_desktop' : 'chat_wallpaper_mobile';

        if ($user->{$field}) {
            $this->deleteS3PathFromUrl($user->{$field});
            $user->update([$field => null]);
        }

        return response()->json([
            'message' => 'Wallpaper removido com sucesso.',
            'type' => $type,
            'data' => $user->fresh(),
        ]);
    }

    public function uploadWelcomeMedia(Request $request, string $id)
    {
        $auth = $request->user();
        $user = User::findOrFail($id);

        if (! $auth?->isAdmin() || (int) $auth->id !== (int) $user->id) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $request->validate([
            'type' => 'required|string|in:image,video,audio',
            'media' => 'required|file',
            'audio_duration' => 'nullable|integer|min:1|max:60',
        ]);

        $type = $request->input('type');
        $file = $request->file('media');

        if ($type === 'image') {
            $request->validate(['media' => 'image|mimes:jpeg,jpg,png,gif,webp|max:25600']);
        } elseif ($type === 'video') {
            $request->validate(['media' => 'mimes:mp4,webm,mov|max:102400']);
        } else {
            $request->validate(['media' => 'mimes:mp3,ogg,m4a,mpeg,wav,aac,mpga,webm|max:10240']);
        }

        $field = match ($type) {
            'image' => 'welcome_image_url',
            'video' => 'welcome_video_url',
            default => 'welcome_audio_url',
        };

        if ($user->{$field}) {
            $this->deleteS3PathFromUrl($user->{$field});
        }

        $ext = $file->getClientOriginalExtension() ?: match ($type) {
            'image' => 'jpg',
            'video' => 'mp4',
            default => 'webm',
        };
        $path = "user/{$user->id}/welcome/{$type}/".time().'_'.uniqid().'.'.$ext;
        Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()), 'public');

        $url = $this->normalizeStoredUrl($this->buildPublicS3Url($path));
        $payload = [$field => $url];

        if ($type === 'audio') {
            $payload['welcome_audio_duration'] = max(1, (int) $request->input('audio_duration', 1));
        }

        $user->update($payload);

        return response()->json([
            'message' => 'Mídia da mensagem inicial atualizada.',
            'type' => $type,
            'url' => $url,
            'audio_duration' => $user->fresh()->welcome_audio_duration,
            'data' => $user->fresh(),
        ]);
    }

    public function deleteWelcomeMedia(Request $request, string $id, string $type)
    {
        $auth = $request->user();
        $user = User::findOrFail($id);

        if (! $auth?->isAdmin() || (int) $auth->id !== (int) $user->id) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        if (! in_array($type, ['image', 'video', 'audio'], true)) {
            return response()->json(['message' => 'Tipo inválido.'], 422);
        }

        $field = match ($type) {
            'image' => 'welcome_image_url',
            'video' => 'welcome_video_url',
            default => 'welcome_audio_url',
        };

        if ($user->{$field}) {
            $this->deleteS3PathFromUrl($user->{$field});
        }

        $payload = [$field => null];
        if ($type === 'audio') {
            $payload['welcome_audio_duration'] = null;
        }

        $user->update($payload);

        return response()->json([
            'message' => 'Mídia removida com sucesso.',
            'type' => $type,
            'data' => $user->fresh(),
        ]);
    }

    private function deleteS3PathFromUrl(?string $url): void
    {
        if (! $url) {
            return;
        }

        try {
            $oldPath = str_replace('/rebeca/', '/', $url);
            $oldPath = parse_url($oldPath, PHP_URL_PATH) ?: $oldPath;
            $oldPath = ltrim((string) $oldPath, '/');
            if (str_starts_with($oldPath, 'rebeca/')) {
                $oldPath = substr($oldPath, 7);
            }
            Storage::disk('s3')->delete($oldPath);
        } catch (\Throwable $e) {
            // silencioso — não bloqueia novo upload
        }
    }

    private function buildPublicS3Url(string $path): string
    {
        $publicUrl = config('filesystems.disks.s3.url');
        $bucket = config('filesystems.disks.s3.bucket');

        if ($publicUrl) {
            if (strpos($publicUrl, 'r2.dev') !== false) {
                return rtrim($publicUrl, '/').'/'.$bucket.'/'.$path;
            }

            return rtrim($publicUrl, '/').'/'.$path;
        }

        $endpoint = config('filesystems.disks.s3.endpoint');

        return rtrim((string) $endpoint, '/').'/'.$bucket.'/'.$path;
    }

    private function normalizeStoredUrl(string $url): string
    {
        $bucket = config('filesystems.disks.s3.bucket');

        return str_replace('/'.$bucket.'/', '/', $url);
    }

    /**
     * Converte valor formatado (R$ 100,00) para número decimal.
     */
    private function converterValorFormatado(string $valorFormatado): float
    {
        // Remove "R$", espaços, pontos (separadores de milhar) e substitui vírgula por ponto
        $valorLimpo = preg_replace('/R\$\s*/', '', $valorFormatado);
        $valorLimpo = str_replace('.', '', $valorLimpo);
        $valorLimpo = str_replace(',', '.', $valorLimpo);

        return (float) $valorLimpo;
    }
}

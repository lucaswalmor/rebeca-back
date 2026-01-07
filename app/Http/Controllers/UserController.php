<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::all();
        
        return response()->json([
            'data' => $users
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255',
            'sobrenome' => 'required|string|max:255',
            'apelido' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
            'telefone' => 'required|string|max:20',
            'data_nascimento' => 'required|date',
            'is_admin' => 'boolean',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user = User::create($validated);

        return response()->json([
            'message' => 'Usuário criado com sucesso.',
            'data' => $user
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::findOrFail($id);
        
        return response()->json([
            'data' => $user
        ]);
    }

    /**
     * Buscar usuário por apelido (rota pública)
     */
    public function findByApelido(string $apelido)
    {
        $user = User::where('apelido', $apelido)->first();
        
        if (!$user) {
            return response()->json([
                'message' => 'Usuário não encontrado.'
            ], 404);
        }
        
        return response()->json([
            'data' => $user
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        // Validação básica
        $validated = $request->validate([
            'nome' => 'sometimes|string|max:255',
            'sobrenome' => 'sometimes|string|max:255',
            'apelido' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => 'sometimes|string|min:6',
            'telefone' => 'sometimes|string|max:20',
            'data_nascimento' => 'sometimes|date',
            'is_admin' => 'sometimes|boolean',
            // Campos do perfil
            'instagram' => 'nullable|string|max:255',
            'telegram' => 'nullable|string|max:255',
            'whatsapp' => 'nullable|string|max:255',
            'x_twitter' => 'nullable|string|max:255',
            'tiktok' => 'nullable|string|max:255',
            'facebook' => 'nullable|string|max:255',
            'privacy' => 'nullable|string',
            'sobre' => 'nullable|string',
            'path_img_banner' => 'nullable|string|max:255',
            'path_img_avatar' => 'nullable|string|max:255',
            'valor_assinatura_mensal' => 'nullable|numeric|min:0',
            'valor_assinatura_trimestral' => 'nullable|numeric|min:0',
            'valor_assinatura_semestral' => 'nullable|numeric|min:0',
            'valor_desconto_trimestral' => 'nullable|numeric|min:0|max:100',
            'valor_desconto_semestral' => 'nullable|numeric|min:0|max:100',
        ]);

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
            'data' => $user->fresh()
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
            'message' => 'Usuário deletado com sucesso.'
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
        $path = "user/{$user->id}/banner/" . time() . '_' . $file->getClientOriginalName();
        Storage::disk('s3')->put($path, file_get_contents($file), 'public');

        // Construir URL completa do R2
        $publicUrl = config('filesystems.disks.s3.url');
        $bucket = config('filesystems.disks.s3.bucket');
        
        if ($publicUrl) {
            if (strpos($publicUrl, 'r2.dev') !== false) {
                $url = rtrim($publicUrl, '/') . '/' . $bucket . '/' . $path;
            } else {
                $url = rtrim($publicUrl, '/') . '/' . $path;
            }
        } else {
            $endpoint = config('filesystems.disks.s3.endpoint');
            $url = rtrim($endpoint, '/') . '/' . $bucket . '/' . $path;
        }

        // Remover /rebeca/ da URL antes de salvar no banco
        $urlToSave = str_replace('/' . $bucket . '/', '/', $url);

        // Atualizar usuário
        $user->update(['path_img_banner' => $urlToSave]);

        return response()->json([
            'message' => 'Banner atualizado com sucesso.',
            'url' => $url,
            'data' => $user->fresh()
        ]);
    }

    /**
     * Upload de avatar do usuário
     */
    public function uploadAvatar(Request $request, string $id)
    {
        $user = User::findOrFail($id);

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
        $path = "user/{$user->id}/avatar/" . time() . '_' . $file->getClientOriginalName();
        Storage::disk('s3')->put($path, file_get_contents($file), 'public');

        // Construir URL completa do R2
        $publicUrl = config('filesystems.disks.s3.url');
        $bucket = config('filesystems.disks.s3.bucket');
        
        if ($publicUrl) {
            if (strpos($publicUrl, 'r2.dev') !== false) {
                $url = rtrim($publicUrl, '/') . '/' . $bucket . '/' . $path;
            } else {
                $url = rtrim($publicUrl, '/') . '/' . $path;
            }
        } else {
            $endpoint = config('filesystems.disks.s3.endpoint');
            $url = rtrim($endpoint, '/') . '/' . $bucket . '/' . $path;
        }

        // Remover /rebeca/ da URL antes de salvar no banco
        $urlToSave = str_replace('/' . $bucket . '/', '/', $url);

        // Atualizar usuário
        $user->update(['path_img_avatar' => $urlToSave]);

        return response()->json([
            'message' => 'Avatar atualizado com sucesso.',
            'url' => $url,
            'data' => $user->fresh()
        ]);
    }

    /**
     * Converte valor formatado (R$ 100,00) para número decimal.
     *
     * @param string $valorFormatado
     * @return float
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

<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Realiza o login do usuário.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['As credenciais fornecidas estão incorretas.'],
            ]);
        }

        // Cria o token de autenticação
        $token = $user->createToken('auth-token')->plainTextToken;

        // Prepara os dados do usuário baseado no tipo
        $userData = $this->prepareUserData($user);

        return response()->json([
            'user' => $userData,
            'token' => $token,
        ]);
    }

    /**
     * Prepara os dados do usuário para retorno.
     */
    private function prepareUserData(User $user): array
    {
        if ($user->isAdmin()) {
            // Admin: todos os campos menos created_at e updated_at
            $userData = $user->toArray();
            unset($userData['created_at'], $userData['updated_at']);

            return $userData;
        }

        // Verificar se o usuário tem assinatura ativa
        $assinaturaAtiva = $this->verificarAssinaturaAtiva($user);

        // Obter status detalhado da assinatura
        $statusAssinatura = $this->obterStatusAssinatura($user);

        // Usuário normal: apenas campos específicos, resto como null
        return [
            'id' => $user->id,
            'nome' => $user->nome,
            'sobrenome' => $user->sobrenome,
            'telefone' => $user->telefone,
            'email' => $user->email,
            'is_admin' => $user->is_admin,
            'apelido' => $user->apelido,
            'assinatura' => $assinaturaAtiva,
            'status_assinatura' => $statusAssinatura,
        ];
    }

    /**
     * Verifica se o usuário possui uma assinatura ativa.
     */
    private function verificarAssinaturaAtiva(User $user): bool
    {
        $hoje = now()->startOfDay();

        $assinaturaAtiva = $user->assinaturas()
            ->where('data_inicio', '<=', $hoje)
            ->where('data_fim', '>=', $hoje)
            ->exists();

        return $assinaturaAtiva;
    }

    /**
     * Obtém o status detalhado da assinatura do usuário.
     */
    private function obterStatusAssinatura(User $user): string
    {
        $hoje = now()->startOfDay();

        // Buscar a assinatura mais recente aprovada
        $assinatura = $user->assinaturas()
            ->where('status', 'aprovado')
            ->orderBy('data_fim', 'desc')
            ->first();

        if (!$assinatura) {
            return 'Sem Assinatura';
        }

        $dataFim = $assinatura->data_fim->startOfDay();
        $diasRestantes = $hoje->diffInDays($dataFim, false);

        if ($dataFim < $hoje) {
            // Assinatura vencida
            return 'Assinatura Vencida';
        } elseif ($diasRestantes <= 5) {
            // Assinatura à vencer (5 dias ou menos)
            return 'Assinatura À Vencer';
        } else {
            // Assinatura ativa
            return 'Assinatura Ativa';
        }
    }

    /**
     * Realiza o registro de um novo usuário.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $request->validate([
            'nome' => 'required|string|max:255',
            'sobrenome' => 'required|string|max:255',
            'apelido' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
            'telefone' => 'required|string|max:20',
            'data_nascimento' => 'required|date',
        ]);

        // Verificar se email já existe
        if (User::where('email', $request->email)->exists()) {
            return response()->json([
                'message' => 'Este email já está cadastrado.',
                'errors' => [
                    'email' => ['Este email já está cadastrado.'],
                ],
            ], 422);
        }

        // Criar usuário
        $user = User::create([
            'nome' => $request->nome,
            'sobrenome' => $request->sobrenome,
            'apelido' => $request->apelido,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'telefone' => $request->telefone,
            'data_nascimento' => $request->data_nascimento,
            'is_admin' => false,
        ]);

        return response()->json([
            'message' => 'Usuário criado com sucesso.',
            'user' => [
                'id' => $user->id,
                'nome' => $user->nome,
                'sobrenome' => $user->sobrenome,
                'apelido' => $user->apelido,
                'email' => $user->email,
            ],
        ], 201);
    }

    /**
     * Realiza o logout do usuário.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        // Revoga todos os tokens do usuário
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logout realizado com sucesso.',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Realiza o login do usuário.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
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
     *
     * @param User $user
     * @return array
     */
    private function prepareUserData(User $user): array
    {
        if ($user->isAdmin()) {
            // Admin: todos os campos menos created_at e updated_at
            $userData = $user->toArray();
            unset($userData['created_at'], $userData['updated_at']);
            return $userData;
        }

        // Usuário normal: apenas campos específicos, resto como null
        return [
            'id' => $user->id,
            'nome' => $user->nome,
            'sobrenome' => $user->sobrenome,
            'telefone' => $user->telefone,
            'email' => $user->email,
            'is_admin' => $user->is_admin,
            'apelido' => $user->apelido,
            'data_nascimento' => null,
            'instagram' => null,
            'telegram' => null,
            'whatsapp' => null,
            'x_twitter' => null,
            'privacy' => null,
            'sobre' => null,
            'valor_assinatura_mensal' => null,
            'valor_assinatura_trimestral' => null,
            'valor_assinatura_semestral' => null,
            'valor_desconto_trimestral' => null,
            'valor_desconto_semestral' => null,
            'email_verified_at' => null,
        ];
    }

    /**
     * Realiza o logout do usuário.
     *
     * @param Request $request
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

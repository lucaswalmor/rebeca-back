<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChatEnqueteRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatEnqueteController extends Controller
{
    /**
     * Salvar resposta da enquete sobre chat em tempo real
     */
    public function store(StoreChatEnqueteRequest $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não autenticado.',
            ], 401);
        }

        // Verificar se o usuário já votou
        if ($user->chat_enquete_voted !== null) {
            return response()->json([
                'message' => 'Você já respondeu esta enquete.',
            ], 400);
        }

        $validated = $request->validated();

        // Atualizar o usuário com a resposta da enquete
        $user->update([
            'chat_enquete_voted' => $validated['resposta']
        ]);

        return response()->json([
            'message' => 'Resposta registrada com sucesso.',
            'data' => [
                'resposta' => $validated['resposta']
            ]
        ]);
    }

    /**
     * Verificar se o usuário já votou na enquete
     */
    public function checkVoteStatus(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não autenticado.',
            ], 401);
        }

        return response()->json([
            'data' => [
                'has_voted' => $user->chat_enquete_voted !== null,
                'resposta' => $user->chat_enquete_voted
            ]
        ]);
    }

    /**
     * Dashboard da enquete - estatísticas e lista de usuários
     */
    public function dashboard(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->isAdmin()) {
            return response()->json([
                'message' => 'Acesso negado. Apenas administradores podem acessar.',
            ], 403);
        }

        // Buscar todos os usuários
        $users = \App\Models\User::select('id', 'apelido', 'chat_enquete_voted')->get();

        // Calcular estatísticas
        $totalUsers = $users->count();
        $votedUsers = $users->where('chat_enquete_voted', '!==', null)->count();
        $notVotedUsers = $totalUsers - $votedUsers;

        // Calcular porcentagens
        $votedPercentage = $totalUsers > 0 ? round(($votedUsers / $totalUsers) * 100, 1) : 0;
        $notVotedPercentage = $totalUsers > 0 ? round(($notVotedUsers / $totalUsers) * 100, 1) : 0;

        // Contar votos positivos e negativos (apenas usuários que votaram)
        $positiveVotes = $users->whereNotNull('chat_enquete_voted')->where('chat_enquete_voted', 1)->count();
        $negativeVotes = $users->whereNotNull('chat_enquete_voted')->where('chat_enquete_voted', 0)->count();

        // Preparar lista de usuários formatada
        $usersList = $users->map(function ($user) {
            return [
                'id' => $user->id,
                'apelido' => $user->apelido,
                'votou' => $user->chat_enquete_voted !== null,
                'resposta' => $user->chat_enquete_voted
            ];
        });

        return response()->json([
            'data' => [
                'estatisticas' => [
                    'total_usuarios' => $totalUsers,
                    'usuarios_votaram' => $votedUsers,
                    'usuarios_faltam' => $notVotedUsers,
                    'porcentagem_votacao' => $votedPercentage,
                    'porcentagem_faltam' => $notVotedPercentage,
                    'votos_positivos' => $positiveVotes,
                    'votos_negativos' => $negativeVotes
                ],
                'usuarios' => $usersList
            ]
        ]);
    }
}
<?php

namespace App\Http\Controllers;

use App\Services\EvolutionClient;
use App\Services\WhatsAppInstanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppInstanceController extends Controller
{
    public function __construct(
        private WhatsAppInstanceService $whatsApp,
        private EvolutionClient $evolution,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode ver o WhatsApp.'], 403);
        }

        $configured = $this->evolution->configured();
        $instance = $this->whatsApp->forAdmin($admin);

        if ($instance && $configured) {
            $instance = $this->whatsApp->sync($instance);
        }

        return response()->json([
            'data' => [
                'configured' => $configured,
                'instance' => $instance ? $this->whatsApp->toArray($instance, $configured) : null,
            ],
        ]);
    }

    public function connect(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode conectar o WhatsApp.'], 403);
        }

        $instance = $this->whatsApp->connect($admin);

        return response()->json([
            'data' => $this->whatsApp->toArray($instance, $this->evolution->configured()),
            'message' => 'Escaneie o QR Code no WhatsApp e aguarde a confirmação.',
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode ver o WhatsApp.'], 403);
        }

        $instance = $this->whatsApp->forAdmin($admin);

        if (! $instance) {
            return response()->json(['message' => 'Nenhuma instância encontrada.'], 404);
        }

        $instance = $this->whatsApp->sync($instance);

        return response()->json([
            'data' => $this->whatsApp->toArray($instance, $this->evolution->configured()),
        ]);
    }

    public function updateNotifyNumber(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode alterar isso.'], 403);
        }

        $request->validate([
            'notify_number' => ['nullable', 'string', 'max:20'],
        ]);

        $instance = $this->whatsApp->forAdmin($admin);

        if (! $instance) {
            return response()->json(['message' => 'Conecte o WhatsApp antes de salvar o número de alerta.'], 422);
        }

        $instance = $this->whatsApp->updateNotifyNumber($instance, $request->input('notify_number'));

        return response()->json([
            'data' => $this->whatsApp->toArray($instance, $this->evolution->configured()),
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode desconectar o WhatsApp.'], 403);
        }

        $instance = $this->whatsApp->forAdmin($admin);

        if (! $instance) {
            return response()->json(['message' => 'Nenhuma instância encontrada.'], 404);
        }

        $instance = $this->whatsApp->disconnect($instance);

        return response()->json([
            'data' => $this->whatsApp->toArray($instance, $this->evolution->configured()),
            'message' => 'WhatsApp desconectado.',
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'Apenas a administradora pode excluir a instância.'], 403);
        }

        $instance = $this->whatsApp->forAdmin($admin);

        if (! $instance) {
            return response()->json(['message' => 'Nenhuma instância encontrada.'], 404);
        }

        $this->whatsApp->delete($instance);

        return response()->json([
            'data' => [
                'configured' => $this->evolution->configured(),
                'instance' => null,
            ],
            'message' => 'Instância excluída.',
        ]);
    }
}

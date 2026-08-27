<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppInstanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvolutionWebhookController extends Controller
{
    public function __invoke(Request $request, WhatsAppInstanceService $whatsApp): JsonResponse
    {
        $whatsApp->handleWebhook($request->all());

        return response()->json([
            'ok' => true,
        ]);
    }
}

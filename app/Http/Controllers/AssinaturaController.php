<?php

namespace App\Http\Controllers;

use App\Models\Assinatura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AssinaturaController extends Controller
{
    public function gerarLinkPagamento(Request $request)
    {
        $request->validate([
            'plano' => 'required|string|in:1_mes,3_meses,6_meses',
            'valor' => 'required|numeric|min:0',
        ]);

        // Debug: Log dos dados recebidos
        Log::info('Dados recebidos para gerar link:', [
            'plano' => $request->plano,
            'valor' => $request->valor,
            'user_id' => Auth::id(),
            'all_data' => $request->all(),
        ]);

        try {
            // Verificar se já existe uma assinatura pendente para este usuário e plano
            $assinaturaExistente = Assinatura::where('user_id', Auth::id())
                ->where('status', 'pendente')
                ->where('plano', $request->plano)
                ->first();

            if ($assinaturaExistente && $assinaturaExistente->link_pagamento) {
                // Já existe uma assinatura pendente com link, reutilizar
                Log::info('Reutilizando assinatura pendente existente:', [
                    'assinatura_id' => $assinaturaExistente->id,
                    'plano' => $request->plano,
                    'link_pagamento' => $assinaturaExistente->link_pagamento
                ]);

                return response()->json([
                    'success' => true,
                    'link' => $assinaturaExistente->link_pagamento,
                    'assinatura_id' => $assinaturaExistente->id,
                    'order_nsu' => $assinaturaExistente->order_nsu,
                    'reutilizado' => true
                ]);
            }

            // Definir datas baseado no plano
            $dataInicio = now();
            $tipoAssinatura = 'mensal'; // padrão

            switch ($request->plano) {
                case '1_mes':
                    $dataFim = $dataInicio->copy()->addMonth();
                    $tipoAssinatura = 'mensal';
                    break;
                case '3_meses':
                    $dataFim = $dataInicio->copy()->addMonths(3);
                    $tipoAssinatura = 'trimestral';
                    break;
                case '6_meses':
                    $dataFim = $dataInicio->copy()->addMonths(6);
                    $tipoAssinatura = 'semestral';
                    break;
            }

            // Criar registro na tabela de assinaturas
            $assinatura = Assinatura::create([
                'user_id' => Auth::id(),
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
                'tipo_assinatura' => $tipoAssinatura,
                'status' => 'pendente',
                'valor' => $request->valor,
                'plano' => $request->plano,
            ]);

            // Gerar order_nsu único
            $orderNsu = 'order-'.$assinatura->id.'-'.time();
            $assinatura->update(['order_nsu' => $orderNsu]);

            // Mapear plano para descrição
            $planos = [
                '1_mes' => 'Assinatura - 1 Mês',
                '3_meses' => 'Assinatura - 3 Meses',
                '6_meses' => 'Assinatura - 6 Meses',
            ];

            $descricao = $planos[$request->plano] ?? 'Assinatura';

            // Preparar payload para a API da InfinitePay
            $payload = [
                'handle' => 'rehantunes06',
                // 'redirect_url' => 'http://192.168.100.223:8080/checkout/success',
                'redirect_url' => 'https://becalima007.vercel.app/checkout/success',
                'webhook_url' => 'https://rebeca.lksoftware.com.br/public/api/webhooks/infinitepay', // URL do webhook para notificações
                // 'webhook_url' => url('/api/webhooks/infinitepay'), // URL do webhook para notificações
                'order_nsu' => $orderNsu,
                'items' => [
                    [
                        'quantity' => 1,
                        'price' => intval($request->valor * 100), // Converter para centavos
                        'description' => $descricao,
                    ],
                ],
            ];

            // Debug: Log do payload que será enviado
            Log::info('Payload para InfinitePay:', [
                'payload' => $payload,
                'valor_original' => $request->valor,
                'valor_em_centavos' => intval($request->valor * 100),
            ]);

            // Fazer requisição para a API da InfinitePay
            $response = Http::post('https://api.infinitepay.io/invoices/public/checkout/links', $payload);

            if ($response->successful()) {
                $data = $response->json();

                // Debug: Log da resposta para entender a estrutura
                Log::info('Resposta InfinitePay:', [
                    'status' => $response->status(),
                    'data' => $data,
                ]);

                // Extrair o link de diferentes possibilidades
                $link = null;
                $possibleKeys = ['link', 'url', 'checkout_link', 'checkout_url', 'payment_url', 'redirect_url'];

                foreach ($possibleKeys as $key) {
                    if (isset($data[$key])) {
                        $link = $data[$key];
                        break;
                    }
                }

                // Se ainda não encontrou, verificar se é uma resposta aninhada
                if (! $link && isset($data['data'])) {
                    foreach ($possibleKeys as $key) {
                        if (isset($data['data'][$key])) {
                            $link = $data['data'][$key];
                            break;
                        }
                    }
                }

                // Verificar se é uma resposta de sucesso com dados aninhados
                if (! $link && isset($data['success']) && $data['success'] && isset($data['data'])) {
                    $nestedData = $data['data'];
                    foreach ($possibleKeys as $key) {
                        if (isset($nestedData[$key])) {
                            $link = $nestedData[$key];
                            break;
                        }
                    }
                }

                if (! $link) {
                    // Remover assinatura criada em caso de erro
                    $assinatura->delete();

                    return response()->json([
                        'success' => false,
                        'message' => 'Link de pagamento não encontrado na resposta da API',
                        'response_data' => $data,
                        'possible_keys_checked' => $possibleKeys,
                    ], 400);
                }

                // Atualizar assinatura com o link gerado
                $assinatura->update([
                    'link_pagamento' => $link,
                ]);

                return response()->json([
                    'success' => true,
                    'link' => $link,
                    'assinatura_id' => $assinatura->id,
                    'order_nsu' => $orderNsu,
                ]);
            } else {
                // Remover assinatura criada em caso de erro
                $assinatura->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao gerar link de pagamento',
                    'error' => $response->body(),
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function consultarStatus(Request $request)
    {
        $request->validate([
            'order_nsu' => 'required|string',
        ]);

        try {
            $assinatura = Assinatura::where('order_nsu', $request->order_nsu)
                ->where('user_id', Auth::id())
                ->first();

            if (! $assinatura) {
                return response()->json([
                    'success' => false,
                    'message' => 'Assinatura não encontrada',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'assinatura' => [
                    'id' => $assinatura->id,
                    'status' => $assinatura->status,
                    'plano' => $assinatura->plano,
                    'valor' => $assinatura->valor,
                    'data_inicio' => $assinatura->data_inicio,
                    'data_fim' => $assinatura->data_fim,
                    'order_nsu' => $assinatura->order_nsu,
                    'link_pagamento' => $assinatura->link_pagamento,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function testarApiInfinitePay(Request $request)
    {
        try {
            $payload = [
                'handle' => 'rehantunes06',
                // 'redirect_url' => 'http://192.168.100.223:8080/checkout/success',
                'redirect_url' => 'https://becalima007.vercel.app/checkout/success',
                'webhook_url' => 'https://rebeca.lksoftware.com.br/public/api/webhooks/infinitepay', // URL do webhook para notificações
                'order_nsu' => 'test-'.time(),
                'items' => [
                    [
                        'quantity' => 1,
                        'price' => 1000,
                        'description' => 'Teste de API',
                    ],
                ],
            ];

            $response = Http::post('https://api.infinitepay.io/invoices/public/checkout/links', $payload);

            return response()->json([
                'success' => $response->successful(),
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body(),
                'json' => $response->json(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function webhookHandler(Request $request)
    {
        try {
            // Log dos dados recebidos do webhook para debug
            Log::info('Webhook InfinitePay recebido:', [
                'dados' => $request->all(),
                'headers' => $request->headers->all(),
                'ip' => $request->ip(),
            ]);

            // Validar dados obrigatórios do webhook
            $request->validate([
                'invoice_slug' => 'required|string',
                'amount' => 'required|integer',
                'paid_amount' => 'required|integer',
                'installments' => 'required|integer',
                'capture_method' => 'required|string|in:credit_card,pix,boleto',
                'transaction_nsu' => 'required|string',
                'order_nsu' => 'required|string',
            ]);

            // Buscar assinatura pelo order_nsu
            $assinatura = Assinatura::where('order_nsu', $request->order_nsu)->first();

            if (!$assinatura) {
                Log::warning('Assinatura não encontrada para order_nsu:', ['order_nsu' => $request->order_nsu]);
                return response()->json(['message' => 'Assinatura não encontrada'], 404);
            }

            // Verificar se o status já foi atualizado para evitar processamento duplicado
            if ($assinatura->status === 'aprovado') {
                Log::info('Webhook já processado anteriormente:', ['order_nsu' => $request->order_nsu]);
                return response()->json(['message' => 'Webhook já processado'], 200);
            }

            // Atualizar assinatura com dados do pagamento
            $assinatura->update([
                'status' => 'aprovado',
                'transaction_nsu' => $request->transaction_nsu,
                'invoice_slug' => $request->invoice_slug,
                'receipt_url' => $request->receipt_url ?? null,
                'paid_amount' => $request->paid_amount / 100, // Converter de centavos para reais
                'installments' => $request->installments,
                'capture_method' => $request->capture_method,
                'payment_date' => now(),
            ]);

            // Log de sucesso
            Log::info('Assinatura atualizada via webhook:', [
                'assinatura_id' => $assinatura->id,
                'order_nsu' => $request->order_nsu,
                'status' => 'aprovado',
                'valor_pago' => $request->paid_amount / 100,
            ]);

            // Aqui você pode adicionar lógica adicional como:
            // - Enviar email de confirmação
            // - Ativar funcionalidades para o usuário
            // - Notificar administradores

            return response()->json(['message' => 'Webhook processado com sucesso'], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erro de validação no webhook:', [
                'erros' => $e->errors(),
                'dados' => $request->all(),
            ]);
            return response()->json(['message' => 'Dados inválidos', 'errors' => $e->errors()], 400);

        } catch (\Exception $e) {
            Log::error('Erro ao processar webhook:', [
                'erro' => $e->getMessage(),
                'dados' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Erro interno do servidor'], 500);
        }
    }

    public function processarCheckoutSuccess(Request $request)
    {
        $request->validate([
            'capture_method' => 'required|string|in:credit_card,pix,boleto',
            'transaction_id' => 'required|string',
            'transaction_nsu' => 'required|string',
            'slug' => 'required|string',
            'order_nsu' => 'required|string',
            'receipt_url' => 'nullable|string',
        ]);

        try {
            // Log dos dados recebidos
            Log::info('Dados recebidos para processar checkout success:', [
                'dados' => $request->all(),
                'headers' => $request->headers->all(),
            ]);

            // Buscar assinatura pelo order_nsu
            $assinatura = Assinatura::where('order_nsu', $request->order_nsu)->first();

            if (!$assinatura) {
                Log::warning('Assinatura não encontrada para order_nsu:', ['order_nsu' => $request->order_nsu]);
                return response()->json([
                    'success' => false,
                    'message' => 'Assinatura não encontrada para o order_nsu informado',
                ], 404);
            }

            // Atualizar assinatura com dados da URL
            $assinatura->update([
                'capture_method' => $request->capture_method,
                'transaction_nsu' => $request->transaction_nsu,
                'invoice_slug' => $request->slug,
                'receipt_url' => $request->receipt_url,
                // Manter status como 'pendente' até confirmar com InfinitePay
            ]);

            Log::info('Assinatura atualizada com dados da URL:', [
                'assinatura_id' => $assinatura->id,
                'order_nsu' => $request->order_nsu,
                'transaction_nsu' => $request->transaction_nsu,
            ]);

            // Agora consultar a API da InfinitePay para verificar o status do pagamento
            Log::info('Consultando status na InfinitePay:', [
                'handle' => 'rehantunes06',
                'order_nsu' => $request->order_nsu,
                'transaction_nsu' => $request->transaction_nsu,
                'slug' => $request->slug,
            ]);

            try {
                $infinitePayPayload = [
                    'handle' => 'rehantunes06',
                    'order_nsu' => $request->order_nsu,
                    'transaction_nsu' => $request->transaction_nsu,
                    'slug' => $request->slug,
                ];

                $infinitePayResponse = Http::post('https://api.infinitepay.io/invoices/public/checkout/payment_check', $infinitePayPayload);

                Log::info('Resposta da InfinitePay:', [
                    'status' => $infinitePayResponse->status(),
                    'body' => $infinitePayResponse->body(),
                    'json' => $infinitePayResponse->json(),
                ]);

                if ($infinitePayResponse->successful()) {
                    $infinitePayData = $infinitePayResponse->json();

                    // Atualizar assinatura com dados da InfinitePay
                    $updateData = [];

                    if (isset($infinitePayData['paid'])) {
                        $updateData['status'] = $infinitePayData['paid'] ? 'aprovado' : 'pendente';
                    }

                    if (isset($infinitePayData['paid_amount'])) {
                        $updateData['paid_amount'] = $infinitePayData['paid_amount'] / 100; // Converter de centavos
                    }

                    if (isset($infinitePayData['installments'])) {
                        $updateData['installments'] = $infinitePayData['installments'];
                    }

                    if (isset($infinitePayData['amount'])) {
                        // Se não tem paid_amount, usar amount
                        if (!isset($updateData['paid_amount'])) {
                            $updateData['paid_amount'] = $infinitePayData['amount'] / 100;
                        }
                    }

                    if (!empty($updateData)) {
                        $assinatura->update($updateData);

                        Log::info('Assinatura atualizada com dados da InfinitePay:', [
                            'assinatura_id' => $assinatura->id,
                            'update_data' => $updateData,
                        ]);
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Dados salvos e status consultado com sucesso',
                        'assinatura' => [
                            'id' => $assinatura->id,
                            'status' => $assinatura->status,
                            'order_nsu' => $assinatura->order_nsu,
                            'transaction_nsu' => $assinatura->transaction_nsu,
                            'paid_amount' => $assinatura->paid_amount,
                            'installments' => $assinatura->installments,
                            'capture_method' => $assinatura->capture_method,
                            'receipt_url' => $assinatura->receipt_url,
                        ],
                        'infinitepay_response' => $infinitePayData,
                    ]);

                } else {
                    Log::error('Erro na resposta da InfinitePay:', [
                        'status' => $infinitePayResponse->status(),
                        'body' => $infinitePayResponse->body(),
                        'json' => $infinitePayResponse->json(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Erro ao consultar status na InfinitePay',
                        'infinitepay_error' => [
                            'status' => $infinitePayResponse->status(),
                            'body' => $infinitePayResponse->body(),
                            'json' => $infinitePayResponse->json(),
                        ],
                    ], 400);
                }

            } catch (\Exception $infinitePayException) {
                Log::error('Exceção ao consultar InfinitePay:', [
                    'erro' => $infinitePayException->getMessage(),
                    'trace' => $infinitePayException->getTraceAsString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao consultar status na InfinitePay',
                    'infinitepay_exception' => $infinitePayException->getMessage(),
                ], 500);
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erro de validação:', [
                'erros' => $e->errors(),
                'dados' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $e->errors(),
            ], 400);

        } catch (\Exception $e) {
            Log::error('Erro interno ao processar checkout success:', [
                'erro' => $e->getMessage(),
                'dados' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function minhasAssinaturas(Request $request)
    {
        try {
            $assinaturas = Assinatura::where('user_id', Auth::id())
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $assinaturas->map(function ($assinatura) {
                    return [
                        'id' => $assinatura->id,
                        'user_id' => $assinatura->user_id,
                        'data_inicio' => $assinatura->data_inicio,
                        'data_fim' => $assinatura->data_fim,
                        'tipo_assinatura' => $assinatura->tipo_assinatura,
                        'status' => $assinatura->status,
                        'order_nsu' => $assinatura->order_nsu,
                        'link_pagamento' => $assinatura->link_pagamento,
                        'valor' => $assinatura->valor,
                        'plano' => $assinatura->plano,
                        'transaction_nsu' => $assinatura->transaction_nsu,
                        'invoice_slug' => $assinatura->invoice_slug,
                        'receipt_url' => $assinatura->receipt_url,
                        'paid_amount' => $assinatura->paid_amount,
                        'installments' => $assinatura->installments,
                        'capture_method' => $assinatura->capture_method,
                        'payment_date' => $assinatura->payment_date,
                        'created_at' => $assinatura->created_at,
                        'updated_at' => $assinatura->updated_at,
                    ];
                })
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function debugDados(Request $request)
    {
        return response()->json([
            'dados_recebidos' => $request->all(),
            'headers' => $request->headers->all(),
            'user' => Auth::user(),
            'user_id' => Auth::id(),
            'token' => $request->bearerToken(),
        ]);
    }
}

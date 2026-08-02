<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class InsufficientCreditsException extends Exception
{
    public function __construct(
        public readonly float $saldo,
        public readonly float $valorNecessario,
        string $message = 'Saldo de créditos insuficiente.',
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'requires_credits' => true,
            'creditos' => round($this->saldo, 2),
            'valor_necessario' => round($this->valorNecessario, 2),
        ], 402);
    }
}

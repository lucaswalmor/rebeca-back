<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tipo',
        'valor',
        'saldo_apos',
        'referencia_tipo',
        'referencia_id',
        'descricao',
        'order_nsu',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'saldo_apos' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

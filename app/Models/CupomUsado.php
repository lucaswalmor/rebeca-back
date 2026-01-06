<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CupomUsado extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cupoms_usados';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'cupom_id',
    ];

    /**
     * Relacionamento com usuário.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento com cupom.
     */
    public function cupom(): BelongsTo
    {
        return $this->belongsTo(Cupom::class);
    }
}

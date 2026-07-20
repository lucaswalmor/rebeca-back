<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'tipo_post',
        'description',
        'preco',
        'status',
        'is_fixed',
    ];

    protected function casts(): array
    {
        return [
            'tipo_post' => 'integer',
            'is_fixed' => 'boolean',
            'preco' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('ordem');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function compras(): HasMany
    {
        return $this->hasMany(PostCompra::class);
    }

    public function getIsLikedAttribute(): bool
    {
        $user = request()->user();

        // Se não encontrou via request()->user(), tentar via Auth guard (para rotas públicas)
        if (! $user && request()->bearerToken()) {
            $user = \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
        }

        if (! $user) {
            return false;
        }

        return $this->likes()->where('user_id', $user->id)->exists();
    }

    public function getLikesCountAttribute(): int
    {
        return $this->likes()->count();
    }
}

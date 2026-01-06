<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'is_admin',
        'nome',
        'sobrenome',
        'apelido',
        'email',
        'password',
        'telefone',
        'data_nascimento',
        'instagram',
        'telegram',
        'whatsapp',
        'x_twitter',
        'privacy',
        'sobre',
        'path_img_banner',
        'path_img_avatar',
        'valor_assinatura_mensal',
        'valor_assinatura_trimestral',
        'valor_assinatura_semestral',
        'valor_desconto_trimestral',
        'valor_desconto_semestral',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'data_nascimento' => 'date',
            'valor_assinatura_mensal' => 'decimal:2',
            'valor_assinatura_trimestral' => 'decimal:2',
            'valor_assinatura_semestral' => 'decimal:2',
            'valor_desconto_trimestral' => 'decimal:2',
            'valor_desconto_semestral' => 'decimal:2',
        ];
    }

    /**
     * Verifica se o usuário é administrador.
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->is_admin === true;
    }

    /**
     * Relacionamento com assinaturas.
     */
    public function assinaturas()
    {
        return $this->hasMany(Assinatura::class);
    }

    /**
     * Relacionamento com cupons.
     */
    public function cupons()
    {
        return $this->hasMany(Cupom::class);
    }

    /**
     * Relacionamento com cupons usados.
     */
    public function cuponsUsados()
    {
        return $this->hasMany(CupomUsado::class);
    }
}

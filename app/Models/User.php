<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; // Adicione esta linha

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'is_admin',
        'is_blocked',
        'notify_new_posts_email',
        'notify_new_chat_message_email',
        'notify_live_email',
        'chat_blocked',
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
        'tiktok',
        'facebook',
        'privacy',
        'sobre',
        'path_img_banner',
        'path_img_avatar',
        'chat_wallpaper_desktop',
        'chat_wallpaper_mobile',
        'welcome_titulo',
        'welcome_body',
        'welcome_image_url',
        'welcome_video_url',
        'welcome_audio_url',
        'welcome_audio_duration',
        'valor_assinatura_mensal',
        'valor_assinatura_trimestral',
        'valor_assinatura_semestral',
        'valor_desconto_trimestral',
        'valor_desconto_semestral',
        'valor_pacote_midia_chat',
        'valor_pacote_audio_chat',
        'valor_imagem_exclusiva_chat',
        'valor_video_exclusivo_chat',
        'chat_media_credits',
        'chat_audio_credits',
        'creditos',
        'last_seen_at',
        'chat_welcome_sent_at',
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
            'is_blocked' => 'boolean',
            'notify_new_posts_email' => 'boolean',
            'notify_new_chat_message_email' => 'boolean',
            'notify_live_email' => 'boolean',
            'chat_blocked' => 'boolean',
            'data_nascimento' => 'date',
            'valor_assinatura_mensal' => 'decimal:2',
            'valor_assinatura_trimestral' => 'decimal:2',
            'valor_assinatura_semestral' => 'decimal:2',
            'valor_desconto_trimestral' => 'decimal:2',
            'valor_desconto_semestral' => 'decimal:2',
            'valor_pacote_midia_chat' => 'decimal:2',
            'valor_pacote_audio_chat' => 'decimal:2',
            'valor_imagem_exclusiva_chat' => 'decimal:2',
            'valor_video_exclusivo_chat' => 'decimal:2',
            'chat_media_credits' => 'integer',
            'chat_audio_credits' => 'integer',
            'creditos' => 'decimal:2',
            'welcome_audio_duration' => 'integer',
            'last_seen_at' => 'datetime',
            'chat_welcome_sent_at' => 'datetime',
        ];
    }

    /**
     * Verifica se o usuário é administrador.
     */
    public function isAdmin(): bool
    {
        return filter_var($this->is_admin, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Verifica se o usuário possui assinatura aprovada e ainda vigente.
     */
    public function hasAssinaturaAprovadaAtiva(): bool
    {
        $hoje = now()->startOfDay();

        return $this->assinaturas()
            ->where('status', 'aprovado')
            ->where('data_inicio', '<=', $hoje)
            ->where('data_fim', '>=', $hoje)
            ->exists();
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

    /**
     * Relacionamento com posts.
     */
    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Relacionamento com compras de posts.
     */
    public function postCompras()
    {
        return $this->hasMany(PostCompra::class);
    }

    /**
     * Relacionamento com likes de posts.
     */
    public function postLikes()
    {
        return $this->hasMany(PostLike::class);
    }

    /**
     * Relacionamento com comentários.
     */
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Relacionamento com respostas de comentários.
     */
    public function commentReplies()
    {
        return $this->hasMany(CommentReply::class);
    }

    public function subscriberConversations()
    {
        return $this->hasMany(Conversation::class, 'subscriber_id');
    }

    public function adminConversations()
    {
        return $this->hasMany(Conversation::class, 'admin_id');
    }

    public function chatMediaPurchases()
    {
        return $this->hasMany(ChatMediaPurchase::class);
    }
}

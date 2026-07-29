<?php

namespace App\Services;

use App\Models\Assinatura;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FakeEngagementService
{
    public const FAKE_EMAIL_DOMAIN = 'engajamento.rebeca.local';

    public const FAKE_USERS_COUNT = 50;

    /**
     * @var list<string>
     */
    private const COMMENT_TEMPLATES = [
        'Amei demais esse conteúdo!',
        'Que demais, continua assim!',
        'Perfeito, valeu por postar!',
        'Ficou incrível!',
        'Top demais!',
        'Arrasou!',
        'Sem palavras...',
        'Quero mais conteúdo assim!',
        'Maravilhoso!',
        'Sensacional!',
        'Muito bom mesmo!',
        'Você é demais!',
        'Conteúdo de qualidade!',
        'Amei o resultado!',
        'Ficou lindo!',
        'Show de bola!',
        'Não canso de ver!',
        'Excelente post!',
        'Mandou bem!',
        'Isso sim é conteúdo!',
    ];

    /**
     * @var list<array{nome: string, sobrenome: string}>
     */
    private const FAKE_NAMES = [
        ['nome' => 'Ana', 'sobrenome' => 'Silva'],
        ['nome' => 'Bruno', 'sobrenome' => 'Costa'],
        ['nome' => 'Camila', 'sobrenome' => 'Souza'],
        ['nome' => 'Diego', 'sobrenome' => 'Oliveira'],
        ['nome' => 'Eduarda', 'sobrenome' => 'Santos'],
        ['nome' => 'Felipe', 'sobrenome' => 'Lima'],
        ['nome' => 'Gabriela', 'sobrenome' => 'Ferreira'],
        ['nome' => 'Henrique', 'sobrenome' => 'Almeida'],
        ['nome' => 'Isabela', 'sobrenome' => 'Rocha'],
        ['nome' => 'João', 'sobrenome' => 'Martins'],
        ['nome' => 'Karina', 'sobrenome' => 'Barbosa'],
        ['nome' => 'Lucas', 'sobrenome' => 'Carvalho'],
        ['nome' => 'Mariana', 'sobrenome' => 'Gomes'],
        ['nome' => 'Nicolas', 'sobrenome' => 'Ribeiro'],
        ['nome' => 'Olivia', 'sobrenome' => 'Araujo'],
        ['nome' => 'Pedro', 'sobrenome' => 'Melo'],
        ['nome' => 'Queila', 'sobrenome' => 'Nunes'],
        ['nome' => 'Rafael', 'sobrenome' => 'Moreira'],
        ['nome' => 'Sofia', 'sobrenome' => 'Teixeira'],
        ['nome' => 'Thiago', 'sobrenome' => 'Correia'],
        ['nome' => 'Ursula', 'sobrenome' => 'Dias'],
        ['nome' => 'Vinicius', 'sobrenome' => 'Castro'],
        ['nome' => 'Wendy', 'sobrenome' => 'Pinto'],
        ['nome' => 'Xavier', 'sobrenome' => 'Cardoso'],
        ['nome' => 'Yasmin', 'sobrenome' => 'Moura'],
        ['nome' => 'Zoe', 'sobrenome' => 'Freitas'],
        ['nome' => 'Arthur', 'sobrenome' => 'Cunha'],
        ['nome' => 'Beatriz', 'sobrenome' => 'Monteiro'],
        ['nome' => 'Caio', 'sobrenome' => 'Peixoto'],
        ['nome' => 'Daniela', 'sobrenome' => 'Campos'],
        ['nome' => 'Enzo', 'sobrenome' => 'Vieira'],
        ['nome' => 'Fernanda', 'sobrenome' => 'Duarte'],
        ['nome' => 'Gustavo', 'sobrenome' => 'Ramos'],
        ['nome' => 'Helena', 'sobrenome' => 'Azevedo'],
        ['nome' => 'Igor', 'sobrenome' => 'Nascimento'],
        ['nome' => 'Julia', 'sobrenome' => 'Batista'],
        ['nome' => 'Kaique', 'sobrenome' => 'Reis'],
        ['nome' => 'Larissa', 'sobrenome' => 'Macedo'],
        ['nome' => 'Matheus', 'sobrenome' => 'Pires'],
        ['nome' => 'Natalia', 'sobrenome' => 'Siqueira'],
        ['nome' => 'Otavio', 'sobrenome' => 'Farias'],
        ['nome' => 'Patricia', 'sobrenome' => 'Andrade'],
        ['nome' => 'Renan', 'sobrenome' => 'Tavares'],
        ['nome' => 'Sabrina', 'sobrenome' => 'Magalhaes'],
        ['nome' => 'Tiago', 'sobrenome' => 'Barros'],
        ['nome' => 'Vitoria', 'sobrenome' => 'Cruz'],
        ['nome' => 'William', 'sobrenome' => 'Lopes'],
        ['nome' => 'Alice', 'sobrenome' => 'Neves'],
        ['nome' => 'Bernardo', 'sobrenome' => 'Sales'],
        ['nome' => 'Clara', 'sobrenome' => 'Pacheco'],
    ];

    public function ensureFakeUsers(int $count = self::FAKE_USERS_COUNT): Collection
    {
        $users = collect();

        for ($i = 1; $i <= $count; $i++) {
            $email = $this->fakeEmail($i);
            $name = self::FAKE_NAMES[($i - 1) % count(self::FAKE_NAMES)];

            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'is_admin' => false,
                    'nome' => $name['nome'],
                    'sobrenome' => $name['sobrenome'],
                    'apelido' => 'fan_rebeca_'.$i,
                    'password' => Hash::make(Str::random(32)),
                    'telefone' => sprintf('1199%07d', $i),
                    'data_nascimento' => now()->subYears(random_int(18, 40))->subDays(random_int(0, 364))->toDateString(),
                ]
            );

            Assinatura::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'order_nsu' => 'fake-sub-'.$user->id,
                ],
                [
                    'data_inicio' => now()->subMonth(),
                    'data_fim' => now()->addYear(),
                    'tipo_assinatura' => 'anual',
                    'status' => 'aprovado',
                    'valor' => 0,
                    'plano' => 'fake',
                    'payment_date' => now()->subMonth(),
                ]
            );

            $users->push($user);
        }

        return $users;
    }

    public function fakeUsersQuery()
    {
        return User::query()->where('email', 'like', '%@'.self::FAKE_EMAIL_DOMAIN);
    }

    public function seedForPost(Post $post, bool $force = false): void
    {
        $fakeUsers = $this->ensureFakeUsers();

        if ($fakeUsers->isEmpty()) {
            return;
        }

        if (! $force && $this->postAlreadyHasFakeEngagement($post)) {
            return;
        }

        $maxAvailable = $fakeUsers->count();
        $likesCount = random_int(1, $maxAvailable);
        $commentsCount = random_int(1, min(10, $maxAvailable));

        $likeUsers = $fakeUsers->shuffle()->take($likesCount);
        $commentUsers = $fakeUsers->shuffle()->take($commentsCount);

        foreach ($likeUsers as $user) {
            PostLike::firstOrCreate([
                'post_id' => $post->id,
                'user_id' => $user->id,
            ]);
        }

        foreach ($commentUsers as $user) {
            $alreadyCommented = Comment::where('post_id', $post->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($alreadyCommented) {
                continue;
            }

            Comment::create([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'comment' => self::COMMENT_TEMPLATES[array_rand(self::COMMENT_TEMPLATES)],
                'created_at' => now()->subMinutes(random_int(1, 240)),
                'updated_at' => now(),
            ]);
        }
    }

    public function seedMissingPosts(): int
    {
        $this->ensureFakeUsers();

        $count = 0;

        Post::query()
            ->orderBy('id')
            ->each(function (Post $post) use (&$count) {
                if ($this->postAlreadyHasFakeEngagement($post)) {
                    return;
                }

                $this->seedForPost($post);
                $count++;
            });

        return $count;
    }

    public function postAlreadyHasFakeEngagement(Post $post): bool
    {
        return PostLike::query()
            ->where('post_id', $post->id)
            ->whereIn('user_id', $this->fakeUsersQuery()->select('id'))
            ->exists();
    }

    public function fakeEmail(int $index): string
    {
        return 'fake'.$index.'@'.self::FAKE_EMAIL_DOMAIN;
    }
}

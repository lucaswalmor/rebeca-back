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
        'Delícia, sua gostosa',
        'Nossa, que mulherão',
        'Tô louco por você',
        'Que corpo perfeito',
        'Me deixou doido agora',
        'Gostosa demais',
        'Quero mais disso',
        'Você é um pecado',
        'Que safada linda',
        'Tá matando os homens',
        'Delícia do caralho',
        'Olha essa bundinha',
        'Sua gostosa do caralho',
        'Tô babando aqui',
        'Que tesão, caralho',
        'Me deixa louco',
        'Posta mais, porra',
        'Você é viciante',
        'Que peito lindo',
        'Queria estar aí agora',
        'Essa boca... puta merda',
        'Você é fogo',
        'Gostosa pra caralho',
        'Não aguento mais de tesão',
        'Que mulherão do caralho',
        'Me faz um privado?',
        'Tô duro só de ver',
        'Sua delícia',
        'Que pose safada',
        'Continua postando assim',
        'Você é a melhor daqui',
        'Que corpo escultural',
        'Quero te chupar todinha',
        'Minha putinha preferida',
        'Olha o tamanho disso',
        'Que bundinha linda',
        'Me deixa maluco',
        'Posta vídeo completo',
        'Você é vício puro',
        'Que safadeza gostosa',
        'Porra, que gostosa',
        'Tô gozando só de olhar',
        'Você me deixa doente de tesão',
        'Que bunda perfeita',
        'Sua putinha safada',
        'Quero te foder toda',
        'Olha essa cintura',
        'Tá me matando de vontade',
        'Que peitão delicioso',
        'Você é pura putaria',
        'Me deixa babando',
        'Quero mamar esses peitos',
        'Que cara de safada gostosa',
        'Posta pelada completa',
        'Você é o meu vício',
        'Tô maluco por essa bunda',
        'Que corpo de deusa',
        'Sua gostosa do inferno',
        'Me chama no privado',
        'Quero te comer agora',
        'Que bundão gostoso',
        'Você fode a minha mente',
        'Olha essa boca chupadora',
        'Tô doido pra te meter',
        'Que mulherão safada',
        'Posta mais nude',
        'Você é perfeita demais',
        'Quero gozar nessa bundinha',
        'Que peitos macios',
        'Sua delícia do caralho',
        'Me deixa sem ar',
        'Que pose de putinha',
        'Tô viciado em você',
        'Quero te lamber essa bundinha',
        'Olha esse corpo pelado',
        'Você é foda pra caralho',
        'Que bundinha redonda',
        'Me faz gozar com esse conteúdo',
        'Sua puta linda',
        'Quero ver você gemendo',
        'Que peito duro gostoso',
        'Tá me deixando louco',
        'Você merece ser fodida',
        'Olha essa perna aberta',
        'Que safadeza deliciosa',
        'Quero te foder de quatro',
        'Sua gostosa preferida',
        'Posta mais vídeo quente',
        'Você é o pecado em pessoa',
        'Que cu lindinho',
        'Tô batendo uma aqui',
        'Me deixa louco de tesão',
        'Que boca de chupar rola',
        'Você é a rainha da putaria',
        'Quero gozar nessa bunda',
        'Olha essa cara safada',
        'Que corpo pra foder',
        'Sua putinha do papai',
        'Tô hard só de ver você',
        'Que mulher pra comer',
        'Continua sendo essa safada',
        'Você me deixa animal',
        'Quero te chupar até gozar',
        'Que peitos pra mamar',
        'Sua delícia maldita',
        'Posta mais, tô gozando',
        'Você é vício e pecado',
        'Que bundinha pra meter',
        'Me deixa doido toda vez',
        'Tô pagando só por isso',
        'Que bundão guloso',
        'Você é a melhor putinha',
        'Quero te ver gemendo',
        'Olha esse corpo nu',
        'Que tesão do caralho',
        'Sua gostosa viciante',
        'Me fode com esse conteúdo',
        'Que peito pra estourar',
        'Você é pura tentação',
        'Tô babando nessa boca',
        'Que safada deliciosa',
        'Quero te comer sem dó',
        'Sua putinha favorita',
        'Posta nudes agora',
        'Você me deixa sem controle',
        'Que corpo pra gozar',
        'Tô louco nessa bundinha',
        'Me deixa doente por você',
        'Que mulherão pra foder',
        'Você é o meu tesão diário',
        'Olha essa pose puta',
        'Que peitos de foder',
        'Sua delícia sem fim',
        'Quero te lamber esse cu',
        'Tô gozado só de imaginar',
        'Você é a rainha do site',
        'Que boca gulosa gostosa',
        'Me chama pra foder',
        'Sua puta perfeita',
        'Continua me matando assim',
    ];

    /**
     * @var list<string>
     */
    private const NAUGHTY_EMOJIS = [
        '🔥', '😈', '💦', '🥵', '🍆', '😏', '👅', '💋', '❤️‍🔥', '🤤',
    ];

    /**
     * @var list<string>
     */
    private const FAKE_NICKNAMES = [
        'loboNoturno',
        'tesaoReal',
        'paiDePutaria',
        'viadoHetero',
        'grossoDemais',
        'dotadoSP',
        'fodeGostosas',
        'nightHunter',
        'roludoRJ',
        'safadoVip',
        'putaLover',
        'duroSempre',
        'machoAlpha',
        'gozaFacil',
        'babaGostosa',
        'peitoFan',
        'bundaMania',
        'privadoHot',
        'viadoDeElite',
        'tesudoBR',
        'foderAgora',
        'hardMode',
        'papaiGrosso',
        'chupaTudo',
        'meterSemDo',
        'vicioHot',
        'nudeAddict',
        'putaAddict',
        'sexoNorte',
        'roludoSul',
        'fogoNoCu',
        'tesaoDiario',
        'machoBruto',
        'gozadorNato',
        'vipSafado',
        'onlyFansBoy',
        'hotViewer',
        'privadoVip',
        'dotadoBH',
        'tesaoMG',
        'fodeTrans',
        'bundaLover',
        'peitaoFan',
        'sexoRapido',
        'noiteQuente',
        'pauDuro21',
        'viadoRico',
        'putariaTop',
        'hardPlayer',
        'gostosaFan',
    ];

    /**
     * @var list<array{nome: string, sobrenome: string}>
     */
    private const FAKE_NAMES = [
        ['nome' => 'Bruno', 'sobrenome' => 'Costa'],
        ['nome' => 'Diego', 'sobrenome' => 'Oliveira'],
        ['nome' => 'Felipe', 'sobrenome' => 'Lima'],
        ['nome' => 'Henrique', 'sobrenome' => 'Almeida'],
        ['nome' => 'João', 'sobrenome' => 'Martins'],
        ['nome' => 'Lucas', 'sobrenome' => 'Carvalho'],
        ['nome' => 'Nicolas', 'sobrenome' => 'Ribeiro'],
        ['nome' => 'Pedro', 'sobrenome' => 'Melo'],
        ['nome' => 'Rafael', 'sobrenome' => 'Moreira'],
        ['nome' => 'Thiago', 'sobrenome' => 'Correia'],
        ['nome' => 'Vinicius', 'sobrenome' => 'Castro'],
        ['nome' => 'Xavier', 'sobrenome' => 'Cardoso'],
        ['nome' => 'Arthur', 'sobrenome' => 'Cunha'],
        ['nome' => 'Caio', 'sobrenome' => 'Peixoto'],
        ['nome' => 'Enzo', 'sobrenome' => 'Vieira'],
        ['nome' => 'Gustavo', 'sobrenome' => 'Ramos'],
        ['nome' => 'Igor', 'sobrenome' => 'Nascimento'],
        ['nome' => 'Kaique', 'sobrenome' => 'Reis'],
        ['nome' => 'Matheus', 'sobrenome' => 'Pires'],
        ['nome' => 'Otavio', 'sobrenome' => 'Farias'],
        ['nome' => 'Renan', 'sobrenome' => 'Tavares'],
        ['nome' => 'Tiago', 'sobrenome' => 'Barros'],
        ['nome' => 'William', 'sobrenome' => 'Lopes'],
        ['nome' => 'Bernardo', 'sobrenome' => 'Sales'],
        ['nome' => 'André', 'sobrenome' => 'Silva'],
        ['nome' => 'Carlos', 'sobrenome' => 'Souza'],
        ['nome' => 'Daniel', 'sobrenome' => 'Santos'],
        ['nome' => 'Eduardo', 'sobrenome' => 'Ferreira'],
        ['nome' => 'Fernando', 'sobrenome' => 'Rocha'],
        ['nome' => 'Gabriel', 'sobrenome' => 'Barbosa'],
        ['nome' => 'Hugo', 'sobrenome' => 'Gomes'],
        ['nome' => 'Iago', 'sobrenome' => 'Araujo'],
        ['nome' => 'Juliano', 'sobrenome' => 'Nunes'],
        ['nome' => 'Leandro', 'sobrenome' => 'Teixeira'],
        ['nome' => 'Marcelo', 'sobrenome' => 'Dias'],
        ['nome' => 'Nathan', 'sobrenome' => 'Pinto'],
        ['nome' => 'Paulo', 'sobrenome' => 'Freitas'],
        ['nome' => 'Ricardo', 'sobrenome' => 'Monteiro'],
        ['nome' => 'Samuel', 'sobrenome' => 'Campos'],
        ['nome' => 'Vitor', 'sobrenome' => 'Duarte'],
        ['nome' => 'Wagner', 'sobrenome' => 'Azevedo'],
        ['nome' => 'Yuri', 'sobrenome' => 'Batista'],
        ['nome' => 'Alex', 'sobrenome' => 'Macedo'],
        ['nome' => 'Bruno', 'sobrenome' => 'Siqueira'],
        ['nome' => 'Cesar', 'sobrenome' => 'Farias'],
        ['nome' => 'Douglas', 'sobrenome' => 'Andrade'],
        ['nome' => 'Emerson', 'sobrenome' => 'Magalhaes'],
        ['nome' => 'Fabio', 'sobrenome' => 'Cruz'],
        ['nome' => 'Guilherme', 'sobrenome' => 'Neves'],
        ['nome' => 'Heitor', 'sobrenome' => 'Pacheco'],
    ];

    public function ensureFakeUsers(int $count = self::FAKE_USERS_COUNT): Collection
    {
        $users = collect();

        for ($i = 1; $i <= $count; $i++) {
            $email = $this->fakeEmail($i);
            $name = self::FAKE_NAMES[($i - 1) % count(self::FAKE_NAMES)];
            $apelido = self::FAKE_NICKNAMES[($i - 1) % count(self::FAKE_NICKNAMES)];

            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'is_admin' => false,
                    'nome' => $name['nome'],
                    'sobrenome' => $name['sobrenome'],
                    'apelido' => $apelido,
                    'password' => Hash::make(Str::random(32)),
                    'telefone' => sprintf('1199%07d', $i),
                    'data_nascimento' => now()->subYears(random_int(18, 40))->subDays(random_int(0, 364))->toDateString(),
                ]
            );

            // Atualiza apelidos antigos do padrão fan_rebeca_*
            if ($user->apelido !== $apelido || str_starts_with((string) $user->apelido, 'fan_rebeca_')) {
                $user->update(['apelido' => $apelido]);
            }

            Assinatura::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'order_nsu' => 'fake-sub-'.$user->id,
                ],
                [
                    'data_inicio' => now()->subMonth(),
                    'data_fim' => now()->addYear(),
                    'tipo_assinatura' => 'semestral',
                    'status' => 'aprovado',
                    'valor' => 0,
                    'plano' => '6_meses',
                    'payment_date' => now()->subMonth(),
                ]
            );

            $users->push($user->fresh());
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
        $likesCount = random_int(1, min(5, $maxAvailable));
        $commentsCount = random_int(1, min(5, $maxAvailable));

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
                'comment' => $this->randomAdultComment(),
                'created_at' => now()->subMinutes(random_int(1, 240)),
                'updated_at' => now(),
            ]);
        }
    }

    private function randomAdultComment(): string
    {
        $comment = self::COMMENT_TEMPLATES[array_rand(self::COMMENT_TEMPLATES)];

        // ~40% dos comentários ganham emoji(s) de safadeza
        if (random_int(1, 100) <= 40) {
            $emojiCount = random_int(1, 3);
            $emojis = '';

            for ($i = 0; $i < $emojiCount; $i++) {
                $emojis .= self::NAUGHTY_EMOJIS[array_rand(self::NAUGHTY_EMOJIS)];
            }

            $comment .= ' '.$emojis;
        }

        return $comment;
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

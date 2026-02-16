<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            [
                'email' => 'lucaswsb52@gmail.com',
                'is_admin' => false,
                'nome' => 'Lucas',
                'sobrenome' => 'Steinbach',
                'apelido' => 'lulu',
                'password' => Hash::make('32329585'),
                'telefone' => '34992021394',
                'data_nascimento' => '1993-11-21',
                'instagram' => null,
                'tiktok' => null,
                'facebook' => null,
                'telegram' => null,
                'whatsapp' => null,
                'x_twitter' => null,
                'privacy' => null,
                'sobre' => null,
                'valor_assinatura_mensal' => null,
                'valor_assinatura_trimestral' => null,
                'valor_assinatura_semestral' => null,
                'valor_desconto_trimestral' => null,
                'valor_desconto_semestral' => null,
            ],
            [
                'email' => 'lucaswsb5222@gmail.com',
                'is_admin' => false,
                'nome' => 'Lucas',
                'sobrenome' => 'Steinbach',
                'apelido' => 'lulu2',
                'password' => Hash::make('32329585'),
                'telefone' => '00000000000',
                'data_nascimento' => '1990-01-01',
                'instagram' => null,
                'tiktok' => null,
                'facebook' => null,
                'telegram' => null,
                'whatsapp' => null,
                'x_twitter' => null,
                'privacy' => null,
                'sobre' => null,
                'valor_assinatura_mensal' => null,
                'valor_assinatura_trimestral' => null,
                'valor_assinatura_semestral' => null,
                'valor_desconto_trimestral' => null,
                'valor_desconto_semestral' => null,
            ],
        ];
        
        foreach ($users as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                $user
            );
        }
    }
}

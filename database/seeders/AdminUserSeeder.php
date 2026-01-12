<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.s
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'rehantuness6@gmail.com'],
            [
                'is_admin' => true,
                'nome' => 'Rebeca',
                'sobrenome' => 'Antunes',
                'apelido' => 'becaLima007',
                'email' => 'becalima007@icloud.com',
                'password' => Hash::make('191201Re!'),
                'telefone' => '34996536393',
                'data_nascimento' => '2001-12-19',
                'instagram' => 'https://www.instagram.com/becalima007',
                'tiktok' => 'https://www.tiktok.com/@becalima0007',
                'facebook' => 'https://www.facebook.com/becalima007',
                'telegram' => '',
                'whatsapp' => '34996536393',
                'x_twitter' => 'https://x.com/becalima007',
                'privacy' => 'https://privacy.com.br/@Becalima007',
                'sobre' => 'Sou uma pessoa que gosta de programar e de jogar video games.',
                'valor_assinatura_mensal' => 100.00,
                'valor_assinatura_trimestral' => 250.00,
                'valor_assinatura_semestral' => 450.00,
                'valor_desconto_trimestral' => 0.00,
                'valor_desconto_semestral' => 0.00,
            ]
        );
    }
}

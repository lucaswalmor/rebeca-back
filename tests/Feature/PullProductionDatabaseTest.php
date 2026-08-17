<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PullProductionDatabaseTest extends TestCase
{
    public function test_command_fails_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('db:pull-production')
            ->expectsOutput('Este comando não pode ser executado em produção!')
            ->assertFailed();
    }

    public function test_command_fails_when_production_credentials_are_missing(): void
    {
        Config::set('database.production_pull.host', null);
        Config::set('database.production_pull.username', null);
        Config::set('database.production_pull.database', null);

        $this->artisan('db:pull-production')
            ->expectsOutput('Variáveis de produção não configuradas no .env (PROD_DB_HOST, PROD_DB_USERNAME, PROD_DB_DATABASE)')
            ->assertFailed();
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;
use Throwable;

class PullProductionDatabase extends Command
{
    protected $signature = 'db:pull-production
                            {--no-clean : Não apaga o arquivo de dump após importar}
                            {--dump-only : Apenas gera o dump, sem importar}
                            {--force : Importa sem pedir confirmação}';

    protected $description = 'Baixa o banco de produção e importa no ambiente local';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Este comando não pode ser executado em produção!');

            return Command::FAILURE;
        }

        $this->info('🚀 Iniciando pull do banco de produção...');

        $prodHost = config('database.production_pull.host');
        $prodPort = config('database.production_pull.port', 3306);
        $prodUser = config('database.production_pull.username');
        $prodPassword = config('database.production_pull.password');
        $prodDatabase = config('database.production_pull.database');
        $localTargetDb = config('database.production_pull.local_target')
            ?: ($prodDatabase ? ($prodDatabase.'_producao') : null);

        if (! $prodHost || ! $prodUser || ! $prodDatabase) {
            $this->error('Variáveis de produção não configuradas no .env (PROD_DB_HOST, PROD_DB_USERNAME, PROD_DB_DATABASE)');

            return Command::FAILURE;
        }

        if (! $localTargetDb) {
            $this->error('Variável de destino local não configurada (PROD_DB_LOCAL_TARGET).');

            return Command::FAILURE;
        }

        $dumpFile = storage_path('app/production_dump_'.date('Y_m_d_His').'.sql');

        $mysqldump = $this->getMysqldumpPath();
        if (! $mysqldump) {
            $this->error('mysqldump não encontrado. No Windows/Laragon, verifique se MySQL está em C:\laragon\bin\mysql.');

            return Command::FAILURE;
        }

        $prodDefaultsFile = null;
        $localDefaultsFile = null;

        try {
            $this->info('📦 Gerando dump do banco de produção...');

            $prodDefaultsFile = storage_path('app/.my_prod_'.uniqid().'.cnf');
            $prodPasswordLine = $prodPassword !== null ? ('password='.addcslashes($prodPassword, '"\\')."\n") : '';
            $prodCnf = "[client]\nuser={$prodUser}\n{$prodPasswordLine}host={$prodHost}\nport={$prodPort}\n";
            file_put_contents($prodDefaultsFile, $prodCnf);
            @chmod($prodDefaultsFile, 0600);

            $dumpCommand = sprintf(
                '%s --defaults-extra-file=%s --protocol=TCP --single-transaction --skip-lock-tables --no-tablespaces --column-statistics=0 --result-file=%s %s 2>&1',
                $mysqldump,
                escapeshellarg($prodDefaultsFile),
                escapeshellarg($dumpFile),
                escapeshellarg($prodDatabase)
            );

            exec($dumpCommand, $output, $returnCode);

            if ($returnCode !== 0 || ! file_exists($dumpFile) || filesize($dumpFile) === 0) {
                $this->warn('mysqldump falhou. Tentando fallback via PDO...');
                if (! empty($output)) {
                    $this->line(implode("\n", $output));
                }

                $pdoCode = $this->dumpUsingPdo($prodHost, (int) $prodPort, $prodDatabase, $prodUser, (string) $prodPassword, $dumpFile);
                if ($pdoCode !== 0 || ! file_exists($dumpFile) || filesize($dumpFile) === 0) {
                    $this->error('Falha ao gerar o dump!');

                    return Command::FAILURE;
                }
            }

            $sizeMb = round(filesize($dumpFile) / 1024 / 1024, 2);
            $this->info("✅ Dump gerado com sucesso! ({$sizeMb} MB) → {$dumpFile}");

            if ($this->option('dump-only')) {
                $this->info('Opção --dump-only ativa. Importação pulada.');

                return Command::SUCCESS;
            }

            $importTargetDb = $localTargetDb;
            $localDevDb = config('database.connections.'.config('database.default').'.database');
            if (! $this->option('force') && ! $this->confirm("⚠️  Será criado/sobrescrito o banco '{$importTargetDb}' no localhost com os dados de produção ('{$prodDatabase}'). Continuar?")) {
                $this->warn('Operação cancelada.');
                @unlink($dumpFile);

                return Command::SUCCESS;
            }

            $mysql = $this->getMysqlPath();
            $localConnection = config('database.connections.'.config('database.default'));
            $localUser = $localConnection['username'] ?? null;
            $localPassword = $localConnection['password'] ?? null;
            $localHost = $localConnection['host'] ?? '127.0.0.1';
            $localPort = $localConnection['port'] ?? 3306;
            $localDefaultsFile = storage_path('app/.my_local_'.uniqid().'.cnf');
            $localPasswordLine = $localPassword !== null ? ('password='.addcslashes($localPassword, '"\\')."\n") : '';
            $localCnf = "[client]\nuser={$localUser}\n{$localPasswordLine}host={$localHost}\nport={$localPort}\n";
            file_put_contents($localDefaultsFile, $localCnf);
            @chmod($localDefaultsFile, 0600);

            $createDbSql = 'CREATE DATABASE IF NOT EXISTS `'.str_replace('`', '``', $importTargetDb).'`';
            $createDbCommand = sprintf(
                '%s --defaults-extra-file=%s -e %s 2>&1',
                $mysql,
                escapeshellarg($localDefaultsFile),
                escapeshellarg($createDbSql)
            );
            exec($createDbCommand, $createDbOut, $createDbCode);
            if ($createDbCode !== 0) {
                $this->error('Falha ao criar banco no localhost. Saída: '.implode("\n", $createDbOut));

                return Command::FAILURE;
            }
            $this->line('Banco "'.$importTargetDb.'" no localhost garantido (criado se não existia).');

            $this->info('💾 Importando no banco local "'.$importTargetDb.'"...');

            $importCommand = sprintf(
                '%s --defaults-extra-file=%s %s < %s 2>&1',
                $mysql,
                escapeshellarg($localDefaultsFile),
                escapeshellarg($importTargetDb),
                escapeshellarg($dumpFile)
            );

            exec($importCommand, $outputImport, $returnCodeImport);

            if ($returnCodeImport !== 0) {
                $this->error('Falha ao importar! Saída: '.implode("\n", $outputImport));

                return Command::FAILURE;
            }

            if (! $this->option('no-clean')) {
                @unlink($dumpFile);
                $this->line('🗑  Arquivo de dump removido.');
            }

            $this->info('✅ Dados de produção importados no banco "'.$importTargetDb.'" no localhost. O banco "'.$localDevDb.'" (desenvolvimento) permanece intacto.');

            return Command::SUCCESS;
        } finally {
            if (! empty($prodDefaultsFile) && file_exists($prodDefaultsFile)) {
                @unlink($prodDefaultsFile);
            }
            if (! empty($localDefaultsFile) && file_exists($localDefaultsFile)) {
                @unlink($localDefaultsFile);
            }
        }
    }

    private function getMysqldumpPath(): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return 'mysqldump';
        }
        $laragonBase = 'C:\\laragon\\bin\\mysql';
        if (! is_dir($laragonBase)) {
            return 'mysqldump';
        }
        $dirs = @scandir($laragonBase) ?: [];
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            $exe = $laragonBase.'\\'.$dir.'\\bin\\mysqldump.exe';
            if (is_file($exe)) {
                return '"'.$exe.'"';
            }
        }

        return 'mysqldump';
    }

    private function getMysqlPath(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return 'mysql';
        }
        $laragonBase = 'C:\\laragon\\bin\\mysql';
        if (! is_dir($laragonBase)) {
            return 'mysql';
        }
        $dirs = @scandir($laragonBase) ?: [];
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            $exe = $laragonBase.'\\'.$dir.'\\bin\\mysql.exe';
            if (is_file($exe)) {
                return '"'.$exe.'"';
            }
        }

        return 'mysql';
    }

    private function dumpUsingPdo(string $host, int $port, string $database, string $username, string $password, string $dumpFile): int
    {
        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 30,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            ]);

            $fp = fopen($dumpFile, 'w');
            if (! $fp) {
                $this->error('Não foi possível criar arquivo de dump (PDO).');

                return 1;
            }

            fwrite($fp, '-- Backup gerado em '.date('Y-m-d H:i:s')."\n");
            fwrite($fp, "-- Database: {$database}\n\n");
            fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $this->line("Exportando tabela (PDO): {$table}");

                $createTable = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
                fwrite($fp, "\n-- Estrutura da tabela `{$table}`\n");
                fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($fp, $createTable['Create Table'].";\n\n");

                $stmt = $pdo->query("SELECT * FROM `{$table}`");
                $wroteHeader = false;
                $batch = 0;

                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    if (! $wroteHeader) {
                        fwrite($fp, "-- Dados da tabela `{$table}`\n");
                        fwrite($fp, "INSERT INTO `{$table}` VALUES\n");
                        $wroteHeader = true;
                        $batch = 0;
                    } else {
                        fwrite($fp, ",\n");
                    }

                    $values = array_map(function ($value) use ($pdo) {
                        if ($value === null) {
                            return 'NULL';
                        }

                        return $pdo->quote((string) $value);
                    }, $row);

                    fwrite($fp, '('.implode(', ', $values).')');
                    $batch++;

                    if ($batch >= 1000) {
                        fwrite($fp, ";\n\n");
                        $wroteHeader = false;
                    }
                }

                if ($wroteHeader) {
                    fwrite($fp, ";\n\n");
                }
            }

            fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($fp);

            return 0;
        } catch (Throwable $e) {
            $this->error('Erro no dump via PDO: '.$e->getMessage());

            return 1;
        }
    }
}

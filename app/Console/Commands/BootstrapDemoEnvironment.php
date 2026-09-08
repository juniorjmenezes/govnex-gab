<?php

namespace App\Console\Commands;

use App\Models\Gabinete;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BootstrapDemoEnvironment extends Command
{
    protected $signature = 'govnexgab:bootstrap-demo
        {--confirm-production : Confirma conscientemente o bootstrap em produção}
        {--admin-name=Admin F3 : Nome do administrador inicial}
        {--admin-email=fabriciovlw1@gmail.com : E-mail do administrador inicial}
        {--credentials-file=credentials.txt : Nome do arquivo criado no diretório protegido}';

    protected $description = 'Inicializa uma demonstração vazia com credenciais fortes e únicas';

    public function handle(DatabaseSeeder $seeder): int
    {
        if (app()->isProduction() && ! $this->option('confirm-production')) {
            $this->components->error('Em produção, informe --confirm-production.');

            return self::FAILURE;
        }

        if (User::withTrashed()->exists() || Gabinete::withoutGlobalScopes()->exists()) {
            $this->components->error('O bootstrap exige um banco sem usuários e gabinetes.');

            return self::FAILURE;
        }

        $adminEmail = mb_strtolower(trim((string) $this->option('admin-email')));
        $adminName = trim((string) $this->option('admin-name'));
        if ($adminName === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error('Nome ou e-mail do administrador inválido.');

            return self::FAILURE;
        }

        try {
            $credentialsPath = $this->credentialsPath();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $accounts = $this->accounts($adminName, $adminEmail);

        try {
            $this->writeCredentials($credentialsPath, $accounts);

            DB::transaction(fn () => $seeder->runForDeployment($accounts), 3);
        } catch (Throwable $exception) {
            @unlink($credentialsPath);
            report($exception);
            $this->components->error('Não foi possível concluir o bootstrap. Consulte os logs sanitizados.');

            return self::FAILURE;
        }

        $this->components->info('Demonstração inicializada.');
        $this->line('Credenciais gravadas no arquivo protegido configurado para o bootstrap.');

        return self::SUCCESS;
    }

    private function credentialsPath(): string
    {
        $file = (string) $this->option('credentials-file');
        if ($file === '' || basename($file) !== $file || ! preg_match('/\A[a-zA-Z0-9._-]+\z/', $file)) {
            throw new RuntimeException('Informe somente um nome de arquivo seguro para as credenciais.');
        }

        $directory = (string) config('govnexgab.bootstrap_directory');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório protegido do bootstrap.');
        }

        $resolved = realpath($directory);
        if ($resolved === false || ! is_writable($resolved)) {
            throw new RuntimeException('O diretório protegido do bootstrap não está disponível para escrita.');
        }

        if (app()->isProduction() && $resolved !== '/bootstrap') {
            throw new RuntimeException('Em produção, GOVNEXGAB_BOOTSTRAP_DIR deve apontar para /bootstrap.');
        }

        $path = $resolved.DIRECTORY_SEPARATOR.$file;
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('O arquivo de credenciais já existe e não será sobrescrito.');
        }

        return $path;
    }

    /** @return array<string, array{name: string, email: string, password: string}> */
    private function accounts(string $adminName, string $adminEmail): array
    {
        return [
            'admin' => $this->account($adminName, $adminEmail),
            'councilor' => $this->account('Vereadora Marina Oliveira', 'vereador@gabinetefacil.test'),
            'chief' => $this->account('Chefe de Gabinete', 'chefe@gabinetefacil.test'),
            'advisor' => $this->account('Assessor Parlamentar', 'assessor@gabinetefacil.test'),
            'second_councilor' => $this->account('Vereador Carlos Nascimento', 'vereador.caucaia@gabinetefacil.test'),
        ];
    }

    /** @return array{name: string, email: string, password: string} */
    private function account(string $name, string $email): array
    {
        return [
            'name' => $name,
            'email' => $email,
            'password' => Str::password(length: 24, symbols: false),
        ];
    }

    /**
     * @param  array<string, array{name: string, email: string, password: string}>  $accounts
     */
    private function writeCredentials(string $path, array $accounts): void
    {
        $lines = [
            'GOVNEX GAB - credenciais iniciais da demonstração',
            'Gerado em: '.now()->toIso8601String(),
            '',
        ];

        foreach ($accounts as $key => $account) {
            $lines[] = sprintf(
                '%s | %s | %s | %s',
                $key,
                $account['name'],
                $account['email'],
                $account['password'],
            );
        }

        if (file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o arquivo protegido de credenciais.');
        }

        chmod($path, 0600);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Diagnóstico somente-leitura dos recursos mínimos para a aplicação
 * funcionar: extensões PHP, limites de upload/execução realmente em vigor
 * no processo que atende a requisição (não o que está escrito no php.ini —
 * um `php artisan serve` desatualizado já nos confundiu sobre isso antes),
 * conectividade com banco/fila e um teste de upload de verdade.
 */
class SystemCheckController extends Controller
{
    /** Extensões PHP usadas diretamente pela aplicação. */
    private const EXTENSIONS = [
        'zip' => 'Leitura dos ZIPs do TSE e anexos compactados.',
        'mbstring' => 'Normalização de texto (nomes, UF, protocolos).',
        'pdo_mysql' => 'Conexão com o banco de dados.',
        'curl' => 'Integrações externas (WhatsApp, PollingData, downloads do TSE).',
        'openssl' => 'Criptografia (APP_KEY, sessões, senhas).',
        'fileinfo' => 'Detecção de tipo de arquivo em uploads.',
        'gd' => 'Processamento de imagens (fotos de candidatos, anexos).',
        'bcmath' => 'Cálculos de precisão exata.',
        // intl fica de fora de propósito: a aplicação não usa nenhum recurso
        // que dependa dela (número/data são formatados manualmente ou no
        // navegador) e, nesta instalação do PHP 8.5.5, ativá-la quebra o
        // boot (TypeError num símbolo ICU, incompatível com a build do
        // php_intl.dll instalada). Não é um requisito, então não entra aqui.
    ];

    /** Diretórios que a aplicação precisa poder escrever. */
    private const WRITABLE_PATHS = [
        'storage/app' => 'storage/app',
        'storage/framework/cache' => 'storage/framework/cache',
        'storage/framework/sessions' => 'storage/framework/sessions',
        'storage/framework/views' => 'storage/framework/views',
        'storage/logs' => 'storage/logs',
        'bootstrap/cache' => 'bootstrap/cache',
    ];

    public function index(Request $request): Response
    {
        return Inertia::render('admin/system/index', [
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
            ],
            'extensions' => collect(self::EXTENSIONS)
                ->map(fn (string $description, string $extension): array => [
                    'name' => $extension,
                    'description' => $description,
                    'loaded' => extension_loaded($extension),
                ])
                ->values()
                ->all(),
            'ini' => $this->iniChecks(),
            'writablePaths' => collect(self::WRITABLE_PATHS)
                ->map(fn (string $relative, string $label): array => [
                    'label' => $label,
                    'writable' => is_writable(base_path($relative)),
                ])
                ->values()
                ->all(),
            'database' => $this->databaseCheck(),
            'queue' => $this->queueCheck(),
            'diskFreeBytes' => @disk_free_space(storage_path()) ?: null,
            'app' => [
                'env' => (string) config('app.env'),
                'debug' => (bool) config('app.debug'),
                'url' => (string) config('app.url'),
                'timezone' => (string) config('app.timezone'),
            ],
            'uploadTest' => $request->session()->get('system_check_upload_test'),
        ]);
    }

    public function testUpload(Request $request): RedirectResponse
    {
        $request->validate([
            // Sem `max:` de propósito — o objetivo é testar o limite real do
            // ambiente (php.ini/.user.ini), não impor um teto artificial do
            // Laravel por cima dele.
            'arquivo' => ['required', 'file'],
        ]);

        $file = $request->file('arquivo');

        $request->session()->flash('system_check_upload_test', [
            'name' => $file->getClientOriginalName(),
            'size_bytes' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'tested_at' => now()->toIso8601String(),
        ]);

        return back();
    }

    /** @return array<string, array{value: string, bytesLimit: int|null}> */
    private function iniChecks(): array
    {
        $directives = [
            'upload_max_filesize',
            'post_max_size',
            'memory_limit',
            'max_execution_time',
            'max_input_time',
        ];

        return collect($directives)
            ->mapWithKeys(fn (string $directive): array => [
                $directive => [
                    'value' => (string) ini_get($directive),
                    'bytesLimit' => $this->toBytes((string) ini_get($directive)),
                ],
            ])
            ->all();
    }

    private function toBytes(string $value): ?int
    {
        if ($value === '' || $value === '-1') {
            return null;
        }

        $unit = mb_strtoupper(mb_substr($value, -1));
        $number = (float) $value;

        return (int) match ($unit) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }

    /** @return array{connected: bool, driver: string, error: string|null} */
    private function databaseCheck(): array
    {
        try {
            DB::connection()->getPdo();
            Schema::hasTable('migrations');

            return [
                'connected' => true,
                'driver' => (string) config('database.default'),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'connected' => false,
                'driver' => (string) config('database.default'),
                'error' => $exception->getMessage(),
            ];
        }
    }

    /** @return array{driver: string, connected: bool, error: string|null} */
    private function queueCheck(): array
    {
        try {
            Queue::connection()->size();

            return [
                'driver' => (string) config('queue.default'),
                'connected' => true,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'driver' => (string) config('queue.default'),
                'connected' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }
}

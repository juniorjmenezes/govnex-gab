<?php

namespace App\Jobs;

use App\Enums\GabineteModule;
use App\Models\SincronizacaoTse;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DownloadAndProcessTseDataset implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(
        public readonly int $runId,
        public readonly ?string $uf = null,
    ) {
        $this->onConnection((string) config('services.tse.queue_connection', 'database'));
        $this->onQueue('tse');
    }

    public function handle(
        TsePoliticalDataSyncService $service,
        ?GabineteModuleManager $modules = null,
    ): void {
        // Ver comentário equivalente em ProcessUploadedTseDataset::handle().
        ini_set('memory_limit', (string) config('services.tse.worker_memory_limit', '2048M'));

        $run = SincronizacaoTse::query()->find($this->runId);

        if (! $run || in_array($run->situacao, ['concluida', 'cancelada'], true)) {
            return;
        }

        $modules ??= app(GabineteModuleManager::class);

        if (! $modules->anyActiveOffice(GabineteModule::Politics)) {
            $run->update([
                'situacao' => 'cancelada',
                'erro' => 'O módulo Inteligência política foi desativado antes do processamento.',
                'concluida_em' => now(),
            ]);

            return;
        }

        $service->syncFromOfficialSource($run, $this->uf);
    }

    public function failed(?Throwable $exception): void
    {
        SincronizacaoTse::query()
            ->whereKey($this->runId)
            ->whereIn('situacao', ['pendente', 'processando'])
            ->update([
                'situacao' => 'falhou',
                'erro' => Str::limit(
                    $exception?->getMessage() ?? 'Falha inesperada ao baixar o dataset do TSE.',
                    10000,
                ),
                'concluida_em' => now(),
            ]);

        Log::warning('Falha no fallback de download automático do TSE.', [
            'sincronizacao_tse_id' => $this->runId,
            'exception' => $exception,
        ]);
    }
}

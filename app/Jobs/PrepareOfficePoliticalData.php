<?php

namespace App\Jobs;

use App\Enums\GabineteModule;
use App\Models\SincronizacaoTse;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\Polls\PollingDataService;
use App\Services\Politics\Polls\ResultResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PrepareOfficePoliticalData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 7200;

    /** @param list<int> $runIds */
    public function __construct(public readonly array $runIds)
    {
        $this->onConnection((string) config('services.tse.queue_connection', 'database'));
        $this->onQueue('tse');
    }

    public function uniqueId(): string
    {
        $runIds = $this->runIds;
        sort($runIds);

        return implode(':', $runIds);
    }

    public function handle(
        PollingDataService $pollingDataService,
        ResultResolver $resultResolver,
        GabineteModuleManager $modules,
    ): void {
        foreach ($this->runIds as $runId) {
            $run = SincronizacaoTse::query()->find($runId);

            if (! $run || in_array($run->situacao, ['concluida', 'cancelada'], true)) {
                continue;
            }

            if ($run->dataset !== 'pollingdata_polls' || $run->gabinete_id === null) {
                $run->forceFill([
                    'situacao' => 'cancelada',
                    'erro' => 'O download automático do TSE foi desativado. Use o upload manual do arquivo oficial.',
                    'concluida_em' => now(),
                ])->save();

                continue;
            }

            $politicsAvailable = $modules->isActive($run->gabinete_id, GabineteModule::Politics);

            if (! $politicsAvailable) {
                $run->forceFill([
                    'situacao' => 'cancelada',
                    'erro' => 'O módulo Inteligência política foi desativado antes do processamento.',
                    'concluida_em' => now(),
                ])->save();

                continue;
            }

            try {
                $pollingDataService->syncQueuedRun($runId, $resultResolver);
            } catch (Throwable $exception) {
                Log::warning('Uma etapa da sincronização política do gabinete falhou.', [
                    'sincronizacao_tse_id' => $runId,
                    'gabinete_id' => $run->gabinete_id,
                    'exception' => $exception,
                ]);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        SincronizacaoTse::query()
            ->whereIn('id', $this->runIds)
            ->whereIn('situacao', ['pendente', 'processando'])
            ->update([
                'situacao' => 'falhou',
                'erro' => mb_substr(
                    $exception?->getMessage() ?? 'Falha inesperada ao executar a sincronização.',
                    0,
                    10000,
                ),
                'concluida_em' => now(),
            ]);
    }
}

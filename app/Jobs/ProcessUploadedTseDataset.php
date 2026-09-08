<?php

namespace App\Jobs;

use App\Enums\GabineteModule;
use App\Models\SincronizacaoTse;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessUploadedTseDataset implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(
        public readonly int $runId,
        public readonly string $archivePath,
        public readonly ?string $uf = null,
    ) {
        $this->onConnection((string) config('services.tse.queue_connection', 'database'));
        $this->onQueue('tse');
    }

    public function handle(
        TsePoliticalDataSyncService $service,
        ?GabineteModuleManager $modules = null,
    ): void {
        // -d memory_limit= na invocação de `queue:listen` não chega até
        // aqui: o listener só repassa a memória pro PRÓPRIO processo, não
        // pro `queue:work --once` que ele spawna pra cada job (ver
        // comentário em config/services.php). ini_set() funciona não
        // importa como o worker foi iniciado.
        ini_set('memory_limit', (string) config('services.tse.worker_memory_limit', '2048M'));

        $run = SincronizacaoTse::query()->find($this->runId);

        if (! $run || in_array($run->situacao, ['concluida', 'cancelada'], true)) {
            File::delete($this->archivePath);

            return;
        }

        $modules ??= app(GabineteModuleManager::class);

        if (! $modules->anyActiveOffice(GabineteModule::Politics)) {
            $run->update([
                'situacao' => 'cancelada',
                'erro' => 'O módulo Inteligência política foi desativado antes do processamento.',
                'concluida_em' => now(),
            ]);
            File::delete($this->archivePath);

            return;
        }

        // syncUploadedDataset() já é dono do próprio try/catch/finally
        // (situacao, checksum, limpeza do arquivo) — não duplicamos nada
        // aqui, só deixamos a exceção propagar pro failed() abaixo cobrir
        // o caso do job falhar fora desse try/catch (ex.: erro fatal).
        $service->syncUploadedDataset($run, $this->archivePath, $this->uf);
    }

    public function failed(?Throwable $exception): void
    {
        SincronizacaoTse::query()
            ->whereKey($this->runId)
            ->whereIn('situacao', ['pendente', 'processando'])
            ->update([
                'situacao' => 'falhou',
                'erro' => Str::limit(
                    $exception?->getMessage() ?? 'Falha inesperada ao processar o dataset enviado.',
                    10000,
                ),
                'concluida_em' => now(),
            ]);

        File::delete($this->archivePath);

        Log::warning('Falha ao processar dataset do TSE enviado manualmente.', [
            'sincronizacao_tse_id' => $this->runId,
            'exception' => $exception,
        ]);
    }
}

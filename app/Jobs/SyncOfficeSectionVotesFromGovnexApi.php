<?php

namespace App\Jobs;

use App\Enums\GabineteModule;
use App\Models\Gabinete;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Importa a votação por seção de um gabinete recém-criado ou recém-relinkado
 * assim que o titular dele é resolvido — ver
 * TsePoliticalDataSyncService::syncOfficeSectionVotes(). Os demais datasets
 * do TSE já cobrem o Brasil inteiro independente de gabinete cadastrado.
 */
class SyncOfficeSectionVotesFromGovnexApi implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(public readonly int $officeId)
    {
        $this->onConnection((string) config('services.tse.queue_connection', 'database'));
        $this->onQueue('tse');
    }

    public function handle(
        TsePoliticalDataSyncService $service,
        GabineteModuleManager $modules,
    ): void {
        $office = Gabinete::withoutGlobalScopes()->find($this->officeId);

        if (
            ! $office instanceof Gabinete
            || ! $office->isActive()
            || ! $modules->isActive($office, GabineteModule::Politics)
        ) {
            return;
        }

        $results = $service->syncOfficeSectionVotes($office);

        Log::info('Votação por seção do gabinete importada pela GOVNEX API.', [
            'gabinete_id' => $office->id,
            'resultados' => $results,
        ]);
    }
}

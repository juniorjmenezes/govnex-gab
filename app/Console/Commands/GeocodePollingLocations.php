<?php

namespace App\Console\Commands;

use App\Enums\GabineteModule;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Console\Command;

class GeocodePollingLocations extends Command
{
    protected $signature = 'tse:geocode-locais-votacao
                            {--limit=100 : Quantidade máxima de locais a geocodificar nesta execução}';

    protected $description = 'Geocodifica pelo endereço os locais de votação do TSE sem latitude/longitude informadas';

    public function handle(
        TsePoliticalDataSyncService $service,
        GabineteModuleManager $modules,
    ): int {
        if (! $modules->anyActiveOffice(GabineteModule::Politics)) {
            $this->info('Nenhum gabinete ativo possui o módulo Inteligência política; geocodificação ignorada.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $resolved = $service->geocodePendingLocations(limit: $limit);

        $this->line("{$resolved} local(is) geocodificado(s).");

        return self::SUCCESS;
    }
}

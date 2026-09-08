<?php

namespace App\Console\Commands;

use App\Services\Politics\Polls\ElectionContext;
use App\Services\Politics\Polls\PollingDataService;
use App\Services\Politics\Polls\ResultResolver;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SyncPollingDataPolls extends Command
{
    protected $signature = 'pollingdata:sync
        {--cargo= : presidente|governador|senador — sincroniza só este cargo (exige --uf)}
        {--uf= : UF (ou BR, só para presidente) — exige --cargo}
        {--ano= : Ano da eleição (padrão: ano atual)}';

    protected $description = 'Sincroniza pesquisas eleitorais publicadas no PollingData (Presidente, Governador, Senador)';

    public function handle(PollingDataService $service, ResultResolver $resultResolver): int
    {
        $cargo = $this->option('cargo');
        $uf = $this->option('uf');
        $ano = (int) ($this->option('ano') ?: now()->year);

        if (($cargo !== null) !== ($uf !== null)) {
            $this->components->error('Informe --cargo e --uf juntos, ou nenhum dos dois para sincronizar o conjunto padrão.');

            return self::FAILURE;
        }

        try {
            $contexts = $cargo !== null && $uf !== null
                ? [$this->buildContext($cargo, $uf, $ano)]
                : $service->defaultContexts($ano);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $results = $service->syncMany($contexts, $resultResolver);
        $failures = 0;

        foreach ($results as $label => $count) {
            if ($count === null) {
                $failures++;
                $this->components->error("{$label}: falhou (ver log)");

                continue;
            }

            $this->components->info(sprintf('%s: %s registros', $label, number_format($count, 0, ',', '.')));
        }

        return $failures > 0 && $failures === count($results) ? self::FAILURE : self::SUCCESS;
    }

    private function buildContext(string $cargo, string $uf, int $ano): ElectionContext
    {
        $uf = mb_strtoupper($uf);

        return match (mb_strtolower($cargo)) {
            'presidente' => $uf === 'BR'
                ? ElectionContext::presidenteNacional($ano)
                : ElectionContext::presidenteEstadual($ano, $uf),
            'governador' => ElectionContext::governador($ano, $uf),
            'senador' => ElectionContext::senador($ano, $uf),
            default => throw new InvalidArgumentException("Cargo não suportado: {$cargo}. Use presidente, governador ou senador."),
        };
    }
}

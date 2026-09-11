<?php

namespace App\Services\Politics;

use App\Enums\ElectionType;
use App\Enums\GabineteModule;
use App\Exceptions\GabineteModuleDisabledException;
use App\Jobs\PrepareOfficePoliticalData;
use App\Models\Eleicao;
use App\Models\Gabinete;
use App\Models\SincronizacaoTse;
use App\Models\User;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\Polls\PollingDataService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OfficePoliticalDataSyncDispatcher
{
    /** @var list<string> */
    public const TASKS = ['pollingdata_polls'];

    /** @var array<string, string> */
    public const TASK_LABELS = [
        'pollingdata_polls' => 'Pesquisas de Presidente (PollingData)',
    ];

    public function __construct(
        private readonly PollingDataService $pollingDataService,
        private readonly GabineteModuleManager $modules,
    ) {}

    /**
     * @param  list<string>  $tasks
     * @return array{runs: Collection<int, SincronizacaoTse>, skipped: list<string>}
     */
    public function queue(Gabinete $office, User $requestedBy, array $tasks = self::TASKS): array
    {
        if (! $this->modules->isActive($office, GabineteModule::Politics)) {
            throw new GabineteModuleDisabledException(GabineteModule::Politics);
        }

        $definitions = $this->taskDefinitions();
        $runs = collect();
        $queuedIds = [];
        $skipped = [];

        foreach (array_values(array_unique($tasks)) as $task) {
            $definition = $definitions[$task] ?? null;

            if ($definition === null) {
                $skipped[] = $task;

                continue;
            }

            $activeRun = SincronizacaoTse::query()
                ->where('gabinete_id', $office->id)
                ->where('dataset', $definition['dataset'])
                ->where('ano', $definition['year'])
                ->whereIn('situacao', ['pendente', 'processando'])
                ->latest('id')
                ->first();

            if ($activeRun instanceof SincronizacaoTse && $this->isStale($activeRun)) {
                $activeRun->update([
                    'situacao' => 'falhou',
                    'erro' => 'Sincronização interrompida sem conclusão; uma nova execução foi solicitada.',
                    'concluida_em' => now(),
                ]);
                $activeRun = null;
            }

            if (! $activeRun instanceof SincronizacaoTse) {
                $activeRun = SincronizacaoTse::query()->create([
                    'gabinete_id' => $office->id,
                    'solicitado_por_id' => $requestedBy->id,
                    'dataset' => $definition['dataset'],
                    'ano' => $definition['year'],
                    'fonte_url' => $this->pollingDataService->sourceUrl(),
                    'situacao' => 'pendente',
                    'iniciada_em' => now(),
                ]);
            }

            $runs->push($activeRun);
            $queuedIds[] = $activeRun->id;
        }

        foreach (array_values(array_unique($queuedIds)) as $runId) {
            PrepareOfficePoliticalData::dispatch([$runId]);
        }

        if ($skipped !== []) {
            Log::warning('Tarefas de sincronização política foram ignoradas.', [
                'gabinete_id' => $office->id,
                'tasks' => $skipped,
            ]);
        }

        return ['runs' => $runs, 'skipped' => $skipped];
    }

    public function restart(SincronizacaoTse $run, Gabinete $office, User $requestedBy): SincronizacaoTse
    {
        if ($run->gabinete_id !== $office->id) {
            throw new RuntimeException('Esta sincronização não pertence a este gabinete.');
        }

        if ($run->dataset !== 'pollingdata_polls') {
            throw new RuntimeException('Só o PollingData é reiniciado por gabinete; os dados do TSE são sincronizados pela GOVNEX API.');
        }

        if ($run->situacao === 'processando') {
            throw new RuntimeException('A sincronização já está em processamento. Aguarde a conclusão.');
        }

        if ($run->situacao !== 'pendente') {
            throw new RuntimeException('Só é possível reiniciar sincronizações pendentes.');
        }

        if (! $this->modules->isActive($office, GabineteModule::Politics)) {
            throw new GabineteModuleDisabledException(GabineteModule::Politics);
        }

        $run->update([
            'situacao' => 'cancelada',
            'erro' => "Reiniciada manualmente por {$requestedBy->name}.",
            'concluida_em' => now(),
        ]);

        $newRun = SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'solicitado_por_id' => $requestedBy->id,
            'dataset' => 'pollingdata_polls',
            'ano' => $run->ano,
            'fonte_url' => $this->pollingDataService->sourceUrl(),
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        PrepareOfficePoliticalData::dispatch([$newRun->id]);

        return $newRun;
    }

    public function cancel(SincronizacaoTse $run, Gabinete $office, User $requestedBy): void
    {
        if ($run->gabinete_id !== $office->id) {
            throw new RuntimeException('Esta sincronização não pertence a este gabinete.');
        }

        if ($run->situacao === 'processando') {
            throw new RuntimeException('A sincronização já está em processamento. Aguarde a conclusão.');
        }

        if ($run->situacao !== 'pendente') {
            throw new RuntimeException('Só é possível cancelar sincronizações pendentes.');
        }

        $run->update([
            'situacao' => 'cancelada',
            'erro' => "Cancelada manualmente por {$requestedBy->name}.",
            'concluida_em' => now(),
        ]);
    }

    /** @return array<string, array{dataset: string, year: int}> */
    public function taskDefinitions(): array
    {
        $generalYear = (int) Eleicao::query()
            ->where('tipo', ElectionType::General)
            ->max('ano');

        return $generalYear > 0
            ? ['pollingdata_polls' => ['dataset' => 'pollingdata_polls', 'year' => $generalYear]]
            : [];
    }

    private function isStale(SincronizacaoTse $run): bool
    {
        $lastActivityAt = $run->updated_at ?? $run->iniciada_em;

        return $lastActivityAt->lte(now()->subMinutes(45));
    }
}

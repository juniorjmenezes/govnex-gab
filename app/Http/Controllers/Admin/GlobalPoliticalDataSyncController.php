<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncFromGovnexApiRequest;
use App\Jobs\SyncDatasetFromGovnexApi;
use App\Models\SincronizacaoTse;
use App\Services\Politics\Tse\GovnexApiSettings;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

class GlobalPoliticalDataSyncController extends Controller
{
    /** Complemento da mensagem de confirmação, por dataset. */
    private const SUBJECTS = [
        'municipalities' => 'da base de municípios',
        'electorate' => 'do eleitorado de :ano',
        'candidates' => 'das candidaturas de :ano',
        'turnout' => 'do comparecimento de :ano',
        'candidate_votes' => 'da votação nominal de :ano',
        'polling_locations' => 'dos locais de votação de :ano',
        'section_votes' => 'da votação por seção de :ano',
        'poll_registry' => 'do registro de pesquisas de :ano',
    ];

    /**
     * Dispara um dataset de TsePoliticalDataSyncService::DATASETS. Não recebe
     * arquivo nem URL de origem: o que identifica a execução é o par
     * dataset + ano, e a base de municípios nem ano tem.
     */
    public function syncFromGovnexApi(
        SyncFromGovnexApiRequest $request,
        TsePoliticalDataSyncService $service,
    ): RedirectResponse {
        $dataset = (string) $request->validated('dataset');
        $year = TsePoliticalDataSyncService::datasetRequiresYear($dataset)
            ? (int) $request->validated('ano')
            : now()->year;

        try {
            $service->assertDatasetPrerequisites($dataset, $year);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['dataset' => $exception->getMessage()]);
        }

        try {
            $queued = Cache::lock($this->enqueueLockKey($dataset, $year), 15)
                ->block(5, function () use ($dataset, $year, $request): bool {
                    if ($this->hasActiveRun($dataset, $year)) {
                        return false;
                    }

                    $run = SincronizacaoTse::query()->create([
                        'gabinete_id' => null,
                        'solicitado_por_id' => $request->user()->id,
                        'dataset' => $dataset,
                        'ano' => $year,
                        'fonte_url' => app(GovnexApiSettings::class)->url(),
                        'situacao' => 'pendente',
                        'iniciada_em' => now(),
                    ]);

                    try {
                        SyncDatasetFromGovnexApi::dispatch($run->id);
                    } catch (Throwable $exception) {
                        $run->update([
                            'situacao' => 'falhou',
                            'erro' => 'Não foi possível adicionar a sincronização à fila: '.$exception->getMessage(),
                            'concluida_em' => now(),
                        ]);

                        throw $exception;
                    }

                    return true;
                });
        } catch (LockTimeoutException) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Outra solicitação deste dataset está sendo registrada. Tente novamente em alguns segundos.',
            ]);

            return back();
        }

        if (! $queued) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Este dataset já possui uma sincronização em andamento ou travada. Cancele-a antes de tentar de novo.',
            ]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Sincronização '.str_replace(':ano', (string) $year, self::SUBJECTS[$dataset]).' via GOVNEX API adicionada à fila.',
        ]);

        return back();
    }

    /** Solicita também a interrupção cooperativa de um job em execução. */
    public function cancel(Request $request, SincronizacaoTse $sync): RedirectResponse
    {
        abort_if($sync->gabinete_id !== null, 404);

        if (! in_array($sync->situacao, ['pendente', 'processando'], true)) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Só é possível cancelar sincronizações pendentes ou em processamento.',
            ]);

            return back();
        }

        $sync->update([
            'situacao' => 'cancelada',
            'erro' => "Cancelada manualmente por {$request->user()->name}.",
            'progresso_etapa' => null,
            'progresso_percentual' => null,
            'concluida_em' => now(),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Sincronização cancelada. Já é possível sincronizar o dataset de novo.',
        ]);

        return back();
    }

    private function hasActiveRun(string $dataset, int $year): bool
    {
        return SincronizacaoTse::query()
            ->whereNull('gabinete_id')
            ->where('dataset', $dataset)
            ->when(
                TsePoliticalDataSyncService::datasetRequiresYear($dataset),
                fn ($query) => $query->where('ano', $year),
            )
            ->whereIn('situacao', ['pendente', 'processando'])
            ->exists();
    }

    private function enqueueLockKey(string $dataset, int $year): string
    {
        return 'tse:enqueue:'.TsePoliticalDataSyncService::datasetHistoryKey($dataset, $year);
    }
}

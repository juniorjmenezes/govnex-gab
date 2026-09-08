<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GabineteModule;
use App\Exceptions\GabineteModuleDisabledException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelOfficePoliticalDataSyncRequest;
use App\Http\Requests\Admin\RestartOfficePoliticalDataSyncRequest;
use App\Http\Requests\Admin\SyncOfficePoliticalDataRequest;
use App\Models\EleitoradoMunicipioSnapshot;
use App\Models\Gabinete;
use App\Models\SincronizacaoTse;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\OfficePoliticalDataSyncDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class OfficePoliticalDataSyncController extends Controller
{
    public function show(
        Request $request,
        Gabinete $office,
        OfficePoliticalDataSyncDispatcher $dispatcher,
        GabineteModuleManager $modules,
    ): RedirectResponse|Response {
        $this->authorize('view', $office);
        abort_unless($request->user()->isRoot(), 403);

        if (! $modules->isActive($office, GabineteModule::Politics)) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'O módulo Inteligência política está desativado para este gabinete.',
            ]);

            return to_route('admin.offices.index');
        }

        $office->loadMissing('municipioEleitoral:id,codigo_tse,codigo_ibge,nome,uf');
        $electorateCount = $office->municipio_eleitoral_id !== null
            ? EleitoradoMunicipioSnapshot::query()
                ->where('municipio_eleitoral_id', $office->municipio_eleitoral_id)
                ->latest('data_referencia')
                ->value('eleitores_aptos')
            : null;

        $politicalSyncs = SincronizacaoTse::query()
            ->where('gabinete_id', $office->id)
            ->latest('id')
            ->get()
            ->unique(fn (SincronizacaoTse $sync): string => "{$sync->dataset}:{$sync->ano}")
            ->take(20)
            ->map(fn (SincronizacaoTse $sync): array => $sync->toSummary())
            ->values()
            ->all();

        return Inertia::render('admin/offices/political-sync', [
            'office' => [
                'id' => $office->id,
                'name' => $office->nome,
                'councilor_name' => $office->vereador_nome,
                'city' => $office->municipio,
                'state' => $office->estado,
                'municipality_linked' => $office->municipioEleitoral !== null,
                'municipality_tse_code' => $office->municipioEleitoral?->codigo_tse,
                'electorate_count' => $electorateCount !== null ? (int) $electorateCount : null,
            ],
            'politicalSyncs' => $politicalSyncs,
            'syncTaskDefinitions' => $dispatcher->taskDefinitions(),
        ]);
    }

    public function store(
        SyncOfficePoliticalDataRequest $request,
        Gabinete $office,
        OfficePoliticalDataSyncDispatcher $dispatcher,
    ): RedirectResponse {
        $result = $dispatcher->queue(
            $office,
            $request->user(),
            $request->validated()['tasks'],
        );
        $runs = $result['runs'];
        $skipped = $result['skipped'];

        $message = $runs->isNotEmpty()
            ? 'Sincronização confirmada na fila. Acompanhe o progresso de cada etapa nesta tela.'
            : 'Nenhuma sincronização pôde ser preparada.';

        if ($skipped !== []) {
            $labels = array_map(
                fn (string $task): string => OfficePoliticalDataSyncDispatcher::TASK_LABELS[$task] ?? $task,
                $skipped,
            );
            $message .= ' Não foi possível preparar: '.implode(', ', $labels).
                ' (nenhuma eleição anterior cadastrada).';
        }

        Inertia::flash('toast', [
            'type' => $runs->isEmpty() ? 'error' : 'success',
            'message' => $message,
        ]);

        return back();
    }

    public function restart(
        RestartOfficePoliticalDataSyncRequest $request,
        Gabinete $office,
        SincronizacaoTse $sync,
        OfficePoliticalDataSyncDispatcher $dispatcher,
    ): RedirectResponse {
        try {
            $dispatcher->restart($sync, $office, $request->user());

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'Sincronização reiniciada. Uma nova execução foi adicionada à fila.',
            ]);
        } catch (GabineteModuleDisabledException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }

        return back();
    }

    public function cancel(
        CancelOfficePoliticalDataSyncRequest $request,
        Gabinete $office,
        SincronizacaoTse $sync,
        OfficePoliticalDataSyncDispatcher $dispatcher,
    ): RedirectResponse {
        try {
            $dispatcher->cancel($sync, $office, $request->user());

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'Sincronização cancelada.',
            ]);
        } catch (RuntimeException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }

        return back();
    }
}

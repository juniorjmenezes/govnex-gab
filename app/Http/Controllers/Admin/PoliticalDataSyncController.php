<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GabineteModule;
use App\Http\Controllers\Controller;
use App\Models\Eleicao;
use App\Models\Gabinete;
use App\Models\SincronizacaoTse;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\OfficePoliticalDataSyncDispatcher;
use App\Services\Politics\Tse\GovnexApiDatasetCatalog;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Central de sincronização dos dados políticos: datasets do TSE publicados
 * na GOVNEX API e pesquisas do PollingData. Os disparos continuam nos
 * controllers de cada fluxo — GlobalPoliticalDataSyncController e
 * OfficePoliticalDataSyncController —; esta tela só reúne o que cada um
 * precisa mostrar.
 */
class PoliticalDataSyncController extends Controller
{
    public function index(
        Request $request,
        GabineteModuleManager $modules,
        OfficePoliticalDataSyncDispatcher $pollingData,
    ): Response {
        $this->authorize('viewAny', Gabinete::class);
        abort_unless($request->user()->isRoot(), 403);

        // Última execução de cada dataset/ano — sem limite de linhas: são no
        // máximo oito datasets por eleição cadastrada.
        $globalSyncs = SincronizacaoTse::query()
            ->whereNull('gabinete_id')
            ->whereIn('dataset', TsePoliticalDataSyncService::DATASETS)
            ->latest('id')
            ->get()
            ->unique(fn (SincronizacaoTse $sync): string => TsePoliticalDataSyncService::datasetHistoryKey(
                $sync->dataset,
                $sync->ano,
            ))
            ->map(fn (SincronizacaoTse $sync): array => $sync->toSummary())
            ->values()
            ->all();

        // O PollingData continua sendo sincronizado por gabinete (o job
        // descarta execução sem gabinete_id e o painel político de cada um lê
        // a própria execução), então a tela lista um gabinete por linha.
        $pollingYear = $pollingData->taskDefinitions()['pollingdata_polls']['year'] ?? null;
        $politicsOfficeIds = $modules->activeOfficeIds(GabineteModule::Politics);
        $latestPollingSyncs = SincronizacaoTse::query()
            ->where('dataset', 'pollingdata_polls')
            ->whereIn('gabinete_id', $politicsOfficeIds)
            ->when($pollingYear !== null, fn ($query) => $query->where('ano', $pollingYear))
            ->latest('id')
            ->get()
            ->unique('gabinete_id')
            ->keyBy('gabinete_id');

        $pollingOffices = Gabinete::withoutGlobalScopes()
            ->whereIn('id', $politicsOfficeIds)
            ->with('entidade:id,nome')
            ->orderBy('nome')
            ->get(['id', 'nome', 'municipio', 'estado', 'entidade_id'])
            ->map(fn (Gabinete $office): array => [
                'id' => $office->id,
                'name' => $office->nome,
                'entidade' => $office->entidade?->nome,
                'city' => $office->municipio,
                'state' => $office->estado,
                'latest_sync' => $latestPollingSyncs->get($office->id)?->toSummary(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/political-sync/index', [
            'globalSyncs' => $globalSyncs,
            'politicsAvailable' => $modules->anyActiveOffice(GabineteModule::Politics),
            'elections' => Eleicao::query()
                ->orderByDesc('ano')
                ->get(['ano', 'tipo'])
                ->map(fn (Eleicao $election): array => [
                    'year' => $election->ano,
                    'type' => $election->tipo->value,
                    'label' => "{$election->ano} · {$election->tipo->label()}",
                ])
                ->values()
                ->all(),
            'datasetSlugPatterns' => collect(TsePoliticalDataSyncService::DATASETS)
                ->mapWithKeys(fn (string $dataset): array => [
                    $dataset => GovnexApiDatasetCatalog::pattern($dataset),
                ])
                ->all(),
            'pollingData' => [
                'year' => $pollingYear,
                'offices' => $pollingOffices,
            ],
        ]);
    }
}

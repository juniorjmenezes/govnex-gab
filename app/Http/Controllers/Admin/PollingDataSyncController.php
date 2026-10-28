<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OfficeModule;
use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Models\TseSync;
use App\Services\Modules\OfficeModuleManager;
use App\Services\Politics\OfficePoliticalDataSyncDispatcher;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sincronização das pesquisas do PollingData, uma linha por gabinete com o
 * módulo Política ativo — os disparos continuam no
 * OfficePoliticalDataSyncController. Os datasets públicos do TSE não estão
 * aqui: são parte do ciclo de vida de cada eleição (ElectionController,
 * tela `admin/elections/show`).
 */
class PollingDataSyncController extends Controller
{
    public function index(
        Request $request,
        OfficeModuleManager $modules,
        OfficePoliticalDataSyncDispatcher $pollingData,
    ): Response {
        $this->authorize('viewAny', Office::class);
        abort_unless($request->user()->isRoot(), 403);

        // O PollingData é sincronizado por gabinete (o job descarta execução
        // sem gabinete_id e o painel político de cada um lê a própria
        // execução), então a tela lista um gabinete por linha.
        $pollingYear = $pollingData->taskDefinitions()['pollingdata_polls']['year'] ?? null;
        $politicsOfficeIds = $modules->activeOfficeIds(OfficeModule::Politics);
        $latestPollingSyncs = TseSync::query()
            ->where('dataset', 'pollingdata_polls')
            ->whereIn('gabinete_id', $politicsOfficeIds)
            ->when($pollingYear !== null, fn ($query) => $query->where('ano', $pollingYear))
            ->latest('id')
            ->get()
            ->unique('gabinete_id')
            ->keyBy('gabinete_id');

        $pollingOffices = Office::withoutGlobalScopes()
            ->whereIn('id', $politicsOfficeIds)
            ->with('entidade:id,nome')
            ->orderBy('nome')
            ->get(['id', 'nome', 'municipio', 'estado', 'entidade_id'])
            ->map(fn (Office $office): array => [
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
            'politicsAvailable' => $modules->anyActiveOffice(OfficeModule::Politics),
            'pollingData' => [
                'year' => $pollingYear,
                'offices' => $pollingOffices,
            ],
        ]);
    }
}

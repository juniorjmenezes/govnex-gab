<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccessRole;
use App\Enums\DemandStatus;
use App\Enums\EntidadeStatus;
use App\Enums\EntidadeType;
use App\Enums\GabineteModule;
use App\Enums\GabineteStatus;
use App\Enums\GabineteType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OfficeRequest;
use App\Http\Requests\Admin\UpdateOfficeModulesRequest;
use App\Http\Requests\Admin\UpdateOfficeStatusRequest;
use App\Jobs\SyncOfficeSectionVotesFromGovnexApi;
use App\Models\Entidade;
use App\Models\Gabinete;
use App\Models\GabineteModulo;
use App\Models\GabineteModuloEvento;
use App\Models\SincronizacaoTse;
use App\Services\Modules\GabineteModuleCatalog;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\OfficeHolderCandidateResolver;
use App\Services\Politics\TsePoliticalDataSyncService;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administração dos gabinetes. Criar entidade ou gabinete não acontece mais
 * aqui: a estrutura nasce no Govnex Hub (`EstruturaNoHubController`,
 * `HubEstruturaSyncService`).
 */
class OfficeController extends Controller
{
    public function edit(Gabinete $office): Response
    {
        $this->authorize('update', $office);

        $office->load([
            'entidade',
            // Responsável = administrador ativo mais antigo do gabinete.
            'membros' => fn ($query) => $query
                ->where('papel', AccessRole::Administrator)
                ->where('ativo', true)
                ->orderBy('ingressou_em')
                ->orderBy('id')
                ->with('usuario'),
        ]);

        return Inertia::render('admin/offices/form', [
            'office' => $office,
            'responsible' => $office->membros->first()?->usuario,
            'entidade' => $office->entidade ? [
                'id' => $office->entidade->id,
                'name' => $office->entidade->nome,
                'type' => $office->entidade->tipo->value,
                'type_label' => $office->entidade->tipo->label(),
                'city' => $office->entidade->municipio,
                'state' => $office->entidade->estado,
                'timezone' => $office->entidade->timezone,
                'gabinetes_count' => $office->entidade->gabinetes()->withoutGlobalScopes()->count(),
                'tipos_gabinete' => array_map(fn (GabineteType $gabineteType): array => [
                    'value' => $gabineteType->value,
                    'label' => $gabineteType->label(),
                    'leader_label' => $gabineteType->leaderLabel(),
                ], $office->entidade->tipo->allowedGabineteTypes()),
            ] : null,
        ]);
    }

    public function index(
        Request $request,
        GabineteModuleCatalog $moduleCatalog,
    ): Response {
        $this->authorize('viewAny', Gabinete::class);
        abort_unless($request->user()->isRoot(), 403);
        $filters = [
            'q' => trim($request->string('q')->toString()),
            'status' => $request->string('status')->toString(),
            'estado' => strtoupper($request->string('estado')->toString()),
        ];

        $paginator = Gabinete::withoutGlobalScopes()
            ->select('gabinetes.*')
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['q'];
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('nome', 'like', "%{$search}%")
                        ->orWhere('vereador_nome', 'like', "%{$search}%")
                        ->orWhere('municipio', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['estado'] !== '', fn (Builder $query) => $query->where('estado', $filters['estado']))
            ->with([
                'entidade:id,nome,slug,tipo,status',
                'membros' => fn ($query) => $query
                    ->where('papel', AccessRole::Administrator)
                    ->where('ativo', true)
                    ->orderBy('ingressou_em')
                    ->orderBy('id')
                    ->with('usuario:id,name,email,is_active,last_login_at'),
                'municipioEleitoral:id,codigo_tse,codigo_ibge,nome,uf',
                'candidatoTitular:id,nome,nome_urna,numero,partido_sigla',
                'modulos:id,gabinete_id,modulo,ativo',
                'moduloEventos' => fn ($query) => $query
                    ->with('administrador:id,name')
                    ->latest('ocorrido_em')
                    ->limit(8),
            ])
            ->withCount([
                'membros as users_count',
                'membros as active_users_count' => fn ($query) => $query
                    ->where('ativo', true)
                    ->whereHas('usuario', fn ($query) => $query->where('is_active', true)),
            ])
            ->addSelect([
                'demands_count' => DB::table('demandas')
                    ->selectRaw('count(*)')
                    ->whereColumn('gabinete_id', 'gabinetes.id'),
                'open_demands_count' => DB::table('demandas')
                    ->selectRaw('count(*)')
                    ->whereColumn('gabinete_id', 'gabinetes.id')
                    ->whereIn('status', DemandStatus::openValues()),
                'citizens_count' => DB::table('cidadaos')
                    ->selectRaw('count(*)')
                    ->whereColumn('gabinete_id', 'gabinetes.id'),
                'electorate_count' => DB::table('eleitorado_municipio_snapshots')
                    ->select('eleitores_aptos')
                    ->whereColumn('municipio_eleitoral_id', 'gabinetes.municipio_eleitoral_id')
                    ->latest('data_referencia')
                    ->limit(1),
                'electorate_reference_date' => DB::table('eleitorado_municipio_snapshots')
                    ->select('data_referencia')
                    ->whereColumn('municipio_eleitoral_id', 'gabinetes.municipio_eleitoral_id')
                    ->latest('data_referencia')
                    ->limit(1),
                'candidates_count' => DB::table('candidatos_politicos')
                    ->selectRaw('count(*)')
                    ->whereColumn('municipio_eleitoral_id', 'gabinetes.municipio_eleitoral_id'),
                'turnout_count' => DB::table('comparecimentos_eleitorais_municipio')
                    ->selectRaw('count(*)')
                    ->whereColumn('municipio_eleitoral_id', 'gabinetes.municipio_eleitoral_id'),
                'candidate_votes_count' => DB::table('votacoes_candidatos_municipio')
                    ->selectRaw('count(*)')
                    ->whereColumn('municipio_eleitoral_id', 'gabinetes.municipio_eleitoral_id'),
                'polling_locations_count' => DB::table('locais_votacao_eleitorais')
                    ->selectRaw('count(*)')
                    ->whereColumn('municipio_eleitoral_id', 'gabinetes.municipio_eleitoral_id'),
                'section_votes_count' => DB::table('votos_secao_candidato')
                    ->selectRaw('count(*)')
                    ->whereColumn('candidato_politico_id', 'gabinetes.candidato_titular_id'),
            ])
            ->latest()
            ->paginate(PerPage::resolve($request, 15))
            ->withQueryString();

        $syncsByOffice = SincronizacaoTse::query()
            ->whereIn('gabinete_id', $paginator->getCollection()->pluck('id'))
            ->latest('id')
            ->get()
            ->groupBy('gabinete_id');
        $officeData = $paginator->getCollection()
            ->map(fn (Gabinete $office): array => [
                'id' => $office->id,
                'name' => $office->nome,
                'slug' => $office->slug,
                'status' => $office->status->value,
                'status_label' => $office->status->label(),
                'hub_linked' => $office->hub_unidade_id !== null,
                'entidade' => $office->entidade ? [
                    'id' => $office->entidade->id,
                    'name' => $office->entidade->nome,
                    'slug' => $office->entidade->slug,
                    'type' => $office->entidade->tipo->value,
                ] : null,
                'councilor_name' => $office->vereador_nome,
                'candidate_number' => $office->numero_eleitoral,
                'office_holder_candidate' => $office->candidatoTitular ? [
                    'id' => $office->candidatoTitular->id,
                    'name' => $office->candidatoTitular->nome,
                    'ballot_name' => $office->candidatoTitular->nome_urna,
                    'number' => $office->candidatoTitular->numero,
                    'party' => $office->candidatoTitular->partido_sigla,
                ] : null,
                'city' => $office->municipio,
                'state' => $office->estado,
                'timezone' => $office->timezone,
                'phone' => $office->telefone,
                'email' => $office->email,
                'address' => $office->endereco,
                'suspended_at' => $office->suspended_at?->toIso8601String(),
                'created_at' => $office->created_at?->toIso8601String(),
                'users_count' => (int) $office->getAttribute('users_count'),
                'active_users_count' => (int) $office->getAttribute('active_users_count'),
                'responsible' => $office->membros->first()?->usuario?->only([
                    'id', 'name', 'email', 'is_active', 'last_login_at',
                ]),
                'demands_count' => (int) $office->getAttribute('demands_count'),
                'open_demands_count' => (int) $office->getAttribute('open_demands_count'),
                'citizens_count' => (int) $office->getAttribute('citizens_count'),
                'municipality_linked' => $office->municipioEleitoral !== null,
                'municipality_tse_code' => $office->municipioEleitoral?->codigo_tse,
                'municipality_ibge_code' => $office->municipioEleitoral?->codigo_ibge,
                'electorate_count' => $office->getAttribute('electorate_count') !== null
                    ? (int) $office->getAttribute('electorate_count')
                    : null,
                'electorate_reference_date' => $office->getAttribute('electorate_reference_date'),
                'political_data_checklist' => [
                    [
                        'key' => 'electorate',
                        'label' => 'Eleitorado',
                        'available' => $office->getAttribute('electorate_count') !== null,
                        'note' => null,
                    ],
                    [
                        'key' => 'candidates',
                        'label' => 'Candidaturas',
                        'available' => (int) $office->getAttribute('candidates_count') > 0,
                        'note' => null,
                    ],
                    [
                        'key' => 'turnout',
                        'label' => 'Comparecimento',
                        'available' => (int) $office->getAttribute('turnout_count') > 0,
                        'note' => null,
                    ],
                    [
                        'key' => 'candidate_votes',
                        'label' => 'Votação nominal',
                        'available' => (int) $office->getAttribute('candidate_votes_count') > 0,
                        'note' => null,
                    ],
                    [
                        'key' => 'polling_locations',
                        'label' => 'Locais de votação',
                        'available' => (int) $office->getAttribute('polling_locations_count') > 0,
                        'note' => null,
                    ],
                    [
                        'key' => 'section_votes',
                        'label' => 'Votação por seção',
                        'available' => (int) $office->getAttribute('section_votes_count') > 0,
                        'note' => $office->candidato_titular_id === null
                            ? 'Depende do titular já resolvido (candidaturas + votação nominal antes).'
                            : null,
                    ],
                ],
                'political_syncs' => $syncsByOffice
                    ->get($office->id, collect())
                    ->unique(fn (SincronizacaoTse $sync): string => TsePoliticalDataSyncService::datasetHistoryKey(
                        $sync->dataset,
                        $sync->ano,
                        $sync->uf,
                    ))
                    ->take(10)
                    ->map(fn (SincronizacaoTse $sync): array => $sync->toSummary())
                    ->values()
                    ->all(),
                'modules' => $office->modulos
                    ->where('ativo', true)
                    ->map(fn (GabineteModulo $setting): string => $setting->modulo->value)
                    ->values()
                    ->all(),
                'module_history' => $office->moduloEventos
                    ->map(fn (GabineteModuloEvento $event): array => [
                        'id' => $event->id,
                        'module' => $event->modulo->value,
                        'action' => $event->acao,
                        'administrator' => $event->administrador?->name,
                        'occurred_at' => $event->ocorrido_em->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();

        $offices = [
            'data' => $officeData,
            'links' => $paginator->linkCollection()->all(),
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];

        return Inertia::render('admin/offices/index', [
            'offices' => $offices,
            'filters' => $filters,
            'statuses' => array_map(
                fn (GabineteStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                GabineteStatus::cases(),
            ),
            'moduleCatalog' => array_values($moduleCatalog->definitions()),
        ]);
    }

    public function updateModules(
        UpdateOfficeModulesRequest $request,
        Gabinete $office,
        GabineteModuleManager $modules,
    ): RedirectResponse {
        $modules->sync($office, $request->validated('modules'), $request->user(), [
            'origem' => 'ADMIN_GABINETE',
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Módulos do gabinete atualizados. Os dados existentes foram preservados.',
        ]);

        return back();
    }

    public function update(
        OfficeRequest $request,
        Gabinete $office,
        OfficeHolderCandidateResolver $holderResolver,
        TsePoliticalDataSyncService $politicalDataSync,
        GabineteModuleManager $modules,
    ): RedirectResponse {
        $validated = $request->validated();
        $municipalityChanged = $validated['municipio'] !== $office->municipio
            || $validated['estado'] !== $office->estado;

        DB::transaction(function () use ($office, $validated, $municipalityChanged, $politicalDataSync): void {
            $office->forceFill(collect($validated)->only([
                'nome', 'vereador_nome', 'numero_eleitoral', 'municipio', 'estado', 'timezone',
                'telefone', 'email', 'endereco', 'numero', 'complemento', 'bairro', 'cep',
            ])->all())->forceFill(['candidato_titular_id' => null])->save();

            if ($municipalityChanged) {
                // Editar o município/estado não realinha sozinho os dados
                // políticos (eleitorado, candidatos, mapa) — eles dependem
                // de municipio_eleitoral_id, uma FK separada só atualizada
                // pela importação do TSE. Sem isso, o painel continuaria
                // mostrando os dados do município antigo indefinidamente.
                $municipality = $politicalDataSync->resolveMunicipality(
                    $validated['estado'],
                    $validated['municipio'],
                );
                $office->forceFill(['municipio_eleitoral_id' => $municipality?->id])->save();
            }

            // Conta e vínculo do responsável vêm do Govnex Hub: esta tela não
            // cria nem altera usuário (docs/INTEGRACAO_GOVNEX_HUB.md).
            if ($office->entidade?->tipo === EntidadeType::IndependentOffice) {
                $office->entidade->forceFill([
                    // Entidade ligada ao Hub recebe o nome de lá, por webhook.
                    ...($office->entidade->hub_entidade_id === null
                        ? ['nome' => $validated['nome']]
                        : []),
                    'municipio' => $validated['municipio'],
                    'estado' => $validated['estado'],
                    'timezone' => $validated['timezone'],
                ])->save();
            }
        });

        $office->refresh();
        $holderResolver->resolveOffice($office);

        if ($municipalityChanged && $modules->isActive($office, GabineteModule::Politics)) {
            SyncOfficeSectionVotesFromGovnexApi::dispatch($office->id);
        }

        Log::info('Gabinete atualizado pela administração da plataforma.', [
            'gabinete_id' => $office->id,
            'administrador_id' => $request->user()->id,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Gabinete atualizado.']);

        return back();
    }

    public function updateStatus(
        UpdateOfficeStatusRequest $request,
        Gabinete $office,
    ): RedirectResponse {
        $status = GabineteStatus::from($request->validated('status'));
        $office->forceFill([
            'status' => $status,
            'suspended_at' => $status === GabineteStatus::Suspended ? now() : null,
        ])->save();

        // Situação de entidade ligada ao Hub é definida lá, por webhook.
        if ($office->entidade?->tipo === EntidadeType::IndependentOffice
            && $office->entidade->hub_entidade_id === null) {
            $office->entidade->forceFill([
                'status' => $status === GabineteStatus::Active
                    ? EntidadeStatus::Active
                    : EntidadeStatus::Suspended,
                'suspensa_em' => $status === GabineteStatus::Suspended ? now() : null,
            ])->save();
        }

        Log::warning('Situação do gabinete alterada pela administração da plataforma.', [
            'gabinete_id' => $office->id,
            'status' => $status->value,
            'administrador_id' => $request->user()->id,
        ]);
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $status === GabineteStatus::Active
                ? 'Gabinete reativado.'
                : 'Gabinete suspenso. Os usuários serão bloqueados.',
        ]);

        return back();
    }
}

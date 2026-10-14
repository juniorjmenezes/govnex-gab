<?php

namespace App\Http\Controllers;

use App\Enums\CommitteeRole;
use App\Enums\CommitteeStatus;
use App\Enums\CommitteeType;
use App\Enums\GabineteStatus;
use App\Enums\GabineteType;
use App\Http\Requests\Committees\CommitteeRequest;
use App\Models\ComissaoLegislativa;
use App\Models\ComissaoMembro;
use App\Models\ConhecimentoDocumento;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use App\Services\Committees\CommitteeMembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Comissões legislativas da Câmara. Leitura (`index`/`show`) para qualquer
 * gabinete da entidade, com o gate de entidade na Policy; o resto só para a
 * Secretaria legislativa, sob `module:SECRETARIA_LEGISLATIVA` na rota.
 *
 * Membros e documentos têm controllers próprios
 * (`CommitteeMemberController`, `CommitteeDocumentController`), como os
 * sub-recursos de demanda.
 */
class CommitteeController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ComissaoLegislativa::class);
        $user = $this->user($request);

        $committees = ComissaoLegislativa::query()
            ->ofEntidade($this->entidadeId($user))
            ->withCount(['membros', 'documentos'])
            // Na listagem, `membros` carrega só o presidente (para a coluna).
            ->with(['membros' => fn ($query) => $query
                ->where('cargo', CommitteeRole::President->value)
                ->with('gabinete:id,nome,vereador_nome')])
            ->orderBy('status')
            ->orderBy('nome')
            ->limit(500)
            ->get()
            ->map(fn (ComissaoLegislativa $comissao): array => [
                ...$this->summary($comissao),
                'members_count' => (int) $comissao->getAttribute('membros_count'),
                'documents_count' => (int) $comissao->getAttribute('documentos_count'),
                'president' => $comissao->membros->first() instanceof ComissaoMembro
                    ? $this->officeLabel($comissao->membros->first()->gabinete)
                    : null,
            ])
            ->values()
            ->all();

        return Inertia::render('committees/index', [
            'committees' => $committees,
            'canManage' => $user->can('create', ComissaoLegislativa::class),
            'options' => ['types' => $this->options(CommitteeType::cases())],
        ]);
    }

    public function store(CommitteeRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $comissao = new ComissaoLegislativa([
            ...$request->committeeData(),
            'status' => CommitteeStatus::Active,
        ]);
        $comissao->forceFill([
            'entidade_id' => $this->entidadeId($user),
            'criado_por_id' => $user->id,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Comissão cadastrada.']);

        return to_route('committees.show', $comissao);
    }

    public function show(Request $request, ComissaoLegislativa $comissao): Response
    {
        $this->authorize('view', $comissao);
        $user = $this->user($request);
        $canManage = $user->can('update', $comissao);

        $members = $comissao->membros()
            ->with(['gabinete:id,nome,vereador_nome', 'usuario:id,name'])
            ->get()
            ->sortBy(fn (ComissaoMembro $membro): string => sprintf(
                '%d-%d-%s',
                $membro->saiu_em === null ? 0 : 1,
                array_search($membro->cargo, CommitteeRole::cases(), true),
                $membro->gabinete->nome,
            ))
            ->map(fn (ComissaoMembro $membro): array => [
                'id' => $membro->id,
                'role' => $membro->cargo->value,
                'role_label' => $membro->cargo->label(),
                'office' => [
                    'id' => $membro->gabinete->id,
                    'name' => $membro->gabinete->nome,
                    'leader' => $membro->gabinete->vereador_nome,
                ],
                'user' => $membro->usuario ? ['id' => $membro->usuario->id, 'name' => $membro->usuario->name] : null,
                'joined_at' => $membro->ingressou_em->toDateString(),
                'left_at' => $membro->saiu_em?->toDateString(),
            ])
            ->values()
            ->all();

        $documents = $comissao->documentos()
            ->orderByPivot('created_at', 'desc')
            ->get(['conhecimento_documentos.id', 'titulo', 'descricao', 'nome_original', 'tamanho', 'total_paginas'])
            ->map(function (ConhecimentoDocumento $documento): array {
                /** @var object{tipo_vinculo: string|null, created_at: mixed} $pivot */
                $pivot = $documento->getRelation('pivot');
                $type = $pivot->tipo_vinculo;

                return [
                    'id' => $documento->id,
                    'title' => $documento->titulo,
                    'description' => $documento->descricao,
                    'pages' => $documento->total_paginas,
                    'link_type' => $type,
                    'link_type_label' => $type !== null ? (ComissaoLegislativa::DOCUMENT_LINK_TYPES[$type] ?? $type) : null,
                    'linked_at' => CarbonImmutable::parse($pivot->created_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('committees/show', [
            'committee' => [
                ...$this->summary($comissao),
                'closed_at' => $comissao->encerrada_em?->toIso8601String(),
            ],
            'members' => $members,
            'documents' => $documents,
            'canManage' => $canManage,
            'options' => $canManage ? $this->managementOptions($comissao) : null,
        ]);
    }

    public function edit(ComissaoLegislativa $comissao): Response
    {
        $this->authorize('update', $comissao);

        return Inertia::render('committees/edit', [
            'committee' => [
                'id' => $comissao->id,
                'nome' => $comissao->nome,
                'sigla' => $comissao->sigla,
                'tipo' => $comissao->tipo->value,
                'status' => $comissao->status->value,
            ],
            'options' => [
                'types' => $this->options(CommitteeType::cases()),
                'statuses' => $this->options(CommitteeStatus::cases()),
            ],
        ]);
    }

    public function update(
        CommitteeRequest $request,
        ComissaoLegislativa $comissao,
        CommitteeMembershipService $membership,
    ): RedirectResponse {
        $data = $request->committeeData();
        $status = CommitteeStatus::from((string) $request->validated('status'));

        DB::transaction(function () use ($comissao, $data, $status, $membership): void {
            $wasActive = $comissao->isActive();
            $comissao->fill($data);

            // Extinguir encerra a participação de quem ainda está nela;
            // reativar devolve os mesmos membros (só a extinção preenche
            // `saiu_em` — remover um membro apaga a linha).
            if ($wasActive && $status === CommitteeStatus::Extinct) {
                $at = now();
                $comissao->encerrada_em = $at;
                $membership->closeAll($comissao, $at);
            } elseif (! $wasActive && $status === CommitteeStatus::Active) {
                $comissao->encerrada_em = null;
                $comissao->membros()->update(['saiu_em' => null]);
            }

            $comissao->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Comissão atualizada.']);

        return to_route('committees.show', $comissao);
    }

    public function destroy(ComissaoLegislativa $comissao): RedirectResponse
    {
        $this->authorize('delete', $comissao);
        $comissao->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Comissão excluída.']);

        return to_route('committees.index');
    }

    /** @return array<string, mixed> */
    private function summary(ComissaoLegislativa $comissao): array
    {
        return [
            'id' => $comissao->id,
            'name' => $comissao->nome,
            'acronym' => $comissao->sigla,
            'type' => $comissao->tipo->value,
            'type_label' => $comissao->tipo->label(),
            'status' => $comissao->status->value,
            'status_label' => $comissao->status->label(),
        ];
    }

    /**
     * Opções da gestão: gabinetes de vereador ativos da Câmara que ainda não
     * estão na comissão (com as pessoas de cada um) e documentos
     * compartilhados com a Câmara ainda não vinculados. Fora do escopo global
     * de `Gabinete`, que no contexto da Secretaria só enxerga ela mesma.
     *
     * @return array<string, mixed>
     */
    private function managementOptions(ComissaoLegislativa $comissao): array
    {
        $memberOffices = $comissao->membros()->pluck('gabinete_id')->map(fn ($id): int => (int) $id)->all();
        $allOffices = Gabinete::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('entidade_id', $comissao->entidade_id)
            ->where('tipo_gabinete', GabineteType::CouncilorOffice)
            ->where('status', GabineteStatus::Active)
            ->orderBy('nome')
            ->get(['id', 'nome', 'vereador_nome']);
        $people = GabineteMembro::query()
            ->whereIn('gabinete_id', $allOffices->pluck('id'))
            ->where('ativo', true)
            ->whereHas('usuario')
            ->with('usuario:id,name')
            ->get()
            ->groupBy('gabinete_id');

        return [
            'offices' => $allOffices
                ->map(fn (Gabinete $office): array => [
                    'id' => $office->id,
                    'name' => $office->nome,
                    'leader' => $office->vereador_nome,
                    'is_member' => in_array($office->id, $memberOffices, true),
                    'people' => $people->get($office->id, collect())
                        ->map(fn (GabineteMembro $vinculo): array => [
                            'id' => $vinculo->usuario->id,
                            'name' => $vinculo->usuario->name,
                        ])
                        ->sortBy('name')
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'roles' => $this->options(CommitteeRole::cases()),
            'documents' => ConhecimentoDocumento::query()
                ->sharedWithEntidade($comissao->entidade_id)
                ->whereNotIn('id', $comissao->documentos()->pluck('conhecimento_documentos.id'))
                ->latest()
                ->limit(500)
                ->get(['id', 'titulo'])
                ->map(fn (ConhecimentoDocumento $documento): array => [
                    'value' => (string) $documento->id,
                    'label' => $documento->titulo,
                ])
                ->values()
                ->all(),
            'link_types' => collect(ComissaoLegislativa::DOCUMENT_LINK_TYPES)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<CommitteeType|CommitteeStatus|CommitteeRole>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(fn (CommitteeType|CommitteeStatus|CommitteeRole $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], $cases);
    }

    private function officeLabel(Gabinete $gabinete): string
    {
        return $gabinete->vereador_nome !== null && $gabinete->vereador_nome !== ''
            ? $gabinete->vereador_nome
            : $gabinete->nome;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->gabinete_id !== null, 403);

        return $user;
    }

    /** A entidade vem do gabinete em contexto, nunca da requisição. */
    private function entidadeId(User $user): int
    {
        $entidadeId = Gabinete::withoutGlobalScopes()->whereKey($user->gabinete_id)->value('entidade_id');
        abort_if($entidadeId === null, 403);

        return (int) $entidadeId;
    }
}

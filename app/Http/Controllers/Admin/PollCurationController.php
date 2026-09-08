<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CandidateScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePesquisaManualRequest;
use App\Http\Requests\Admin\UpdatePesquisaResultadosRequest;
use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\PesquisaEleitoral;
use App\Models\PesquisaFonte;
use App\Services\Politics\Polls\ResultadoColeta;
use App\Services\Politics\Polls\ResultResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Curadoria manual de pesquisas eleitorais: cobre o que o PollingData não
 * traz (governador, senador, prefeito — o dataset público só tem Presidente;
 * ver [[pollingdata-investigation]] na memória do projeto) permitindo
 * digitar, à mão, os números direto do PDF ou matéria original. Toda
 * gravação passa pelo {@see ResultResolver}, então nunca sobrescreve
 * silenciosamente um resultado já persistido por uma fonte de confiança
 * igual ou maior.
 */
class PollCurationController extends Controller
{
    public function create(Request $request): Response
    {
        abort_unless($request->user()->isRoot(), 403);

        return Inertia::render('admin/polls/create', [
            'elections' => Eleicao::query()
                ->orderByDesc('ano')
                ->get()
                ->map(fn (Eleicao $election): array => [
                    'id' => $election->id,
                    'name' => $election->nome,
                    'year' => $election->ano,
                ]),
        ]);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->isRoot(), 403);

        $elections = Eleicao::query()->orderByDesc('ano')->get();
        $filters = [
            'eleicao_id' => $request->integer('eleicao_id') ?: null,
            'cargo' => $request->string('cargo')->toString(),
            'uf' => mb_strtoupper($request->string('uf')->toString()),
            'q' => trim($request->string('q')->toString()),
        ];

        $paginator = PesquisaEleitoral::query()
            ->with([
                'resultados' => fn ($query) => $query->orderByDesc('percentual'),
                'fontes' => fn ($query) => $query->orderByDesc('id'),
            ])
            ->when(
                $filters['eleicao_id'] !== null,
                fn (Builder $query) => $query->where('eleicao_id', $filters['eleicao_id']),
            )
            ->when($filters['cargo'] !== '', fn (Builder $query) => $query->where('cargo', $filters['cargo']))
            ->when($filters['uf'] !== '', fn (Builder $query) => $query->where('uf', $filters['uf']))
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['q'];
                $query->where(fn (Builder $query) => $query
                    ->where('instituto', 'like', "%{$search}%")
                    ->orWhere('municipio', 'like', "%{$search}%"));
            })
            ->orderByDesc('publicada_em')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $pesquisas = $paginator->getCollection()
            ->map(fn (PesquisaEleitoral $pesquisa): array => $this->serialize($pesquisa))
            ->all();

        return Inertia::render('admin/polls/index', [
            'elections' => $elections->map(fn (Eleicao $election): array => [
                'id' => $election->id,
                'name' => $election->nome,
                'year' => $election->ano,
            ]),
            'filters' => $filters,
            'pesquisas' => [
                'data' => $pesquisas,
                'links' => $paginator->linkCollection()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function candidates(Request $request): JsonResponse
    {
        abort_unless($request->user()->isRoot(), 403);

        $eleicaoId = $request->integer('eleicao_id');
        $cargo = $request->string('cargo')->toString();
        $uf = mb_strtoupper($request->string('uf')->toString());
        $municipio = trim($request->string('municipio')->toString());
        $search = trim($request->string('q')->toString());

        if ($eleicaoId <= 0 || $cargo === '') {
            return response()->json([]);
        }

        $candidates = CandidatoPolitico::query()
            ->where('eleicao_id', $eleicaoId)
            ->where('cargo', $cargo)
            ->when($cargo !== 'presidente' && $uf !== '', function (Builder $query) use ($uf, $municipio): void {
                $query->where(function (Builder $query) use ($uf, $municipio): void {
                    $query->where('abrangencia', CandidateScope::National->value)
                        ->orWhere(function (Builder $state) use ($uf): void {
                            $state->where('abrangencia', CandidateScope::State->value)
                                ->where('uf', $uf);
                        });

                    if ($municipio !== '') {
                        $query->orWhere(function (Builder $municipal) use ($uf, $municipio): void {
                            $municipal->where('abrangencia', CandidateScope::Municipal->value)
                                ->whereHas('municipio', fn (Builder $query) => $query
                                    ->where('nome', $municipio)
                                    ->where('uf', $uf));
                        });
                    }
                });
            })
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(fn (Builder $query) => $query
                    ->where('nome_urna', 'like', "%{$search}%")
                    ->orWhere('nome', 'like', "%{$search}%")
                    ->orWhere('numero', 'like', "%{$search}%"));
            })
            ->orderBy('nome_urna')
            ->limit(30)
            ->get();

        return response()->json($candidates->map(fn (CandidatoPolitico $candidate): array => [
            'id' => $candidate->id,
            'name' => $candidate->nome_urna,
            'party' => $candidate->partido_sigla,
            'number' => $candidate->numero,
        ]));
    }

    public function store(StorePesquisaManualRequest $request, ResultResolver $resolver): RedirectResponse
    {
        $data = $request->validated();
        $ano = Eleicao::query()->whereKey($data['eleicao_id'])->value('ano');

        $pesquisa = PesquisaEleitoral::query()->create([
            'eleicao_id' => $data['eleicao_id'],
            'external_id' => (string) Str::uuid(),
            'external_election_id' => (string) Str::uuid(),
            'ano' => $ano,
            'uf' => $data['uf'],
            'municipio' => $data['municipio'] ?? null,
            'cargo' => $data['cargo'],
            'turno' => $data['turno'],
            'cenario' => $data['cenario'],
            'instituto' => $data['instituto'],
            'publicada_em' => $data['publicada_em'],
            'coleta_inicio_em' => $data['coleta_inicio_em'] ?? null,
            'coleta_fim_em' => $data['coleta_fim_em'] ?? null,
            'tamanho_amostra' => $data['tamanho_amostra'] ?? null,
            'margem_erro' => $data['margem_erro'] ?? null,
            'metodologia' => $data['metodologia'] ?? null,
            'abrangencia' => $data['abrangencia'] ?? null,
            'tipo' => $data['tipo'] ?? null,
            'fonte_url' => $data['fonte_url'],
            'fonte_atualizada_em' => now(),
        ]);

        $candidatos = $this->buildCandidatos($data['candidatos']);
        $resolver->persist($pesquisa, new ResultadoColeta(
            provider: $data['provider'],
            tipo: 'manual',
            confidenceScore: $data['confidence_score'],
            candidatos: $candidatos,
            url: $data['fonte_url'],
            metadata: array_filter([
                'candidatos' => $candidatos,
                'observacao' => $data['observacao'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
        ));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Pesquisa registrada manualmente.',
        ]);

        return to_route('admin.polls.index');
    }

    public function updateResultados(
        UpdatePesquisaResultadosRequest $request,
        PesquisaEleitoral $pesquisa,
        ResultResolver $resolver,
    ): RedirectResponse {
        $data = $request->validated();
        $willApply = $pesquisa->confianca === null || $data['confidence_score'] >= $pesquisa->confianca;
        $candidatos = $this->buildCandidatos($data['candidatos']);

        $resolver->persist($pesquisa, new ResultadoColeta(
            provider: $data['provider'],
            tipo: 'manual',
            confidenceScore: $data['confidence_score'],
            candidatos: $candidatos,
            url: $data['url'] ?? $pesquisa->fonte_url,
            metadata: array_filter([
                'candidatos' => $candidatos,
                'observacao' => $data['observacao'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
        ));

        Inertia::flash('toast', [
            'type' => $willApply ? 'success' : 'info',
            'message' => $willApply
                ? 'Resultados atualizados.'
                : "Registro guardado para auditoria, mas não aplicado: esta pesquisa já tem uma fonte com confiança maior ou igual ({$pesquisa->confianca}).",
        ]);

        return back();
    }

    public function destroy(Request $request, PesquisaEleitoral $pesquisa): RedirectResponse
    {
        abort_unless($request->user()->isRoot(), 403);

        $hasAutomatedSource = $pesquisa->fontes()->where('tipo', '!=', 'manual')->exists();

        if ($hasAutomatedSource) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Esta pesquisa tem origem automatizada (ex.: PollingData) e não pode ser excluída pela curadoria manual.',
            ]);

            return back();
        }

        $pesquisa->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Pesquisa removida.',
        ]);

        return back();
    }

    /**
     * @param  list<array{nome: string, partido: ?string, percentual: float|string, candidato_politico_id: ?int}>  $rows
     * @return list<array{external_candidate_id: string, candidato_politico_id: ?int, nome: string, partido: ?string, percentual: float}>
     */
    private function buildCandidatos(array $rows): array
    {
        $used = [];
        $candidatos = [];

        foreach ($rows as $row) {
            $candidatoPoliticoId = $row['candidato_politico_id'] ?? null;
            $base = $candidatoPoliticoId !== null
                ? "cpid-{$candidatoPoliticoId}"
                : 'nome-'.Str::slug($row['nome']);
            $externalId = $base;
            $suffix = 2;

            while (in_array($externalId, $used, true)) {
                $externalId = "{$base}-{$suffix}";
                $suffix++;
            }

            $used[] = $externalId;
            $candidatos[] = [
                'external_candidate_id' => $externalId,
                'candidato_politico_id' => $candidatoPoliticoId,
                'nome' => $row['nome'],
                'partido' => $row['partido'] ?? null,
                'percentual' => (float) $row['percentual'],
            ];
        }

        return $candidatos;
    }

    /** @return array<string, mixed> */
    private function serialize(PesquisaEleitoral $pesquisa): array
    {
        return [
            'id' => $pesquisa->id,
            'eleicao_id' => $pesquisa->eleicao_id,
            'cargo' => $pesquisa->cargo,
            'uf' => $pesquisa->uf,
            'municipio' => $pesquisa->municipio,
            'turno' => $pesquisa->turno,
            'cenario' => $pesquisa->cenario,
            'instituto' => $pesquisa->instituto,
            'publicada_em' => $pesquisa->publicada_em->toDateString(),
            'coleta_inicio_em' => $pesquisa->coleta_inicio_em?->toDateString(),
            'coleta_fim_em' => $pesquisa->coleta_fim_em?->toDateString(),
            'tamanho_amostra' => $pesquisa->tamanho_amostra,
            'margem_erro' => $pesquisa->margem_erro,
            'metodologia' => $pesquisa->metodologia,
            'abrangencia' => $pesquisa->abrangencia,
            'tipo' => $pesquisa->tipo,
            'fonte_url' => $pesquisa->fonte_url,
            'confianca' => $pesquisa->confianca,
            'origem_provider' => $pesquisa->origem_provider,
            'deletable' => ! $pesquisa->fontes->contains(fn (PesquisaFonte $fonte): bool => $fonte->tipo !== 'manual'),
            'resultados' => $pesquisa->resultados->map(fn ($resultado): array => [
                'candidato_politico_id' => $resultado->candidato_politico_id,
                'external_candidate_id' => $resultado->external_candidate_id,
                'nome' => $resultado->candidato_nome,
                'partido' => $resultado->partido_sigla,
                'percentual' => $resultado->percentual,
            ])->all(),
            'fontes' => $pesquisa->fontes->take(6)->map(fn (PesquisaFonte $fonte): array => [
                'id' => $fonte->id,
                'tipo' => $fonte->tipo,
                'provider' => $fonte->provider,
                'status' => $fonte->status,
                'confidence_score' => $fonte->confidence_score,
                'coletado_em' => $fonte->coletado_em?->toIso8601String(),
                'observacao' => $fonte->metadata['observacao'] ?? null,
            ])->all(),
        ];
    }
}

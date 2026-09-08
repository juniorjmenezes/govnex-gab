<?php

namespace App\Services\Politics\Polls;

use App\Enums\CandidateScope;
use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\PesquisaEleitoral;
use App\Models\SincronizacaoTse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Descoberta + ingestão do PollingData (https://flex.pollingdata.com.br) via
 * `/api/polls/candidates?url=...` — o parâmetro `url` filtra de verdade por
 * corrida eleitoral (confirmado: Presidente, Governador e Senador, nacional
 * ou por UF, devolvem só o subconjunto daquele contexto). Por isso o fluxo
 * é sempre "uma requisição por {@see ElectionContext} conhecido de
 * antemão" — nunca mais uma busca genérica seguida de inferência de
 * cargo/UF/turno/ano a partir do payload (esses quatro campos vêm sempre do
 * contexto, nunca de regex em cima de `cenarioNome`).
 *
 * Cobre `presidente`, `governador` e `senador` — prefeito continua fora
 * (nenhum padrão de URL conhecido para esse cargo).
 *
 * Cada linha do payload é um candidato/opção dentro de um cenário de uma
 * pesquisa. Como o schema atual (herdado do ElectioLab) já trata "uma linha
 * de pesquisas_eleitorais" como "um cenário" (nunca mistura cenários
 * diferentes na mesma pesquisa), a identidade aqui é
 * `(cargo, registro ?? chave-sintética, cenarioId)`. O `cargo` entrou na
 * chave depois de uma auditoria real: o mesmo registro pode conter
 * perguntas de mais de um cargo (ex.: CE-04292/2026 tem Governador
 * cenarioId=1 E Senador cenarioId=1 — questionários diferentes, mesmo
 * número), então `registro+cenarioId` sozinho colidiria e misturaria dados
 * de corridas diferentes numa única `pesquisas_eleitorais`.
 *
 * Como PesquisaResultProvider, é usado pelo ResultResolver para reconsultar
 * o resultado de uma pesquisa já conhecida.
 */
class PollingDataService implements PesquisaResultProvider
{
    /**
     * Pesquisas com registro TSE/PesqEle são verificáveis externamente (dá
     * pra conferir no PesqEle) — mais confiável que a agregação opaca do
     * ElectioLab (60). Sem registro (a fonte não informou), fica abaixo do
     * ElectioLab: não temos como auditar.
     */
    private const CONFIDENCE_WITH_REGISTRO = 70;

    private const CONFIDENCE_WITHOUT_REGISTRO = 55;

    private const UUID_NAMESPACE = 'pollingdata';

    /** 26 estados + DF — mesma lista usada em App\Http\Requests\Admin\OfficeRequest. */
    private const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG',
        'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    /** @var array<string, array<string, mixed>|null> Cache em memória por contexto — evita repetir uma chamada já feita nesta execução. */
    private array $cachedPayloads = [];

    public function sourceUrl(): string
    {
        return (string) config('services.pollingdata.url');
    }

    public function supports(PesquisaEleitoral $pesquisa): bool
    {
        return in_array($pesquisa->cargo, ['presidente', 'governador', 'senador'], true);
    }

    public function buscar(PesquisaEleitoral $pesquisa): ?ResultadoColeta
    {
        if (! $this->supports($pesquisa)) {
            return null;
        }

        $context = new ElectionContext(
            $pesquisa->ano,
            $pesquisa->cargo,
            $pesquisa->uf,
            $pesquisa->turno,
            $this->canonicalSlug($pesquisa->cargo, $pesquisa->uf),
        );

        try {
            $payload = $this->fetch($context);
        } catch (Throwable $exception) {
            Log::warning('Falha ao buscar dados do PollingData.', [
                'context' => $context->label(),
                'exception' => $exception,
            ]);

            return null;
        }

        if ($payload === null || ($payload['noData'] ?? false) === true) {
            return null;
        }

        $registrosMeta = is_array($payload['registrosMeta'] ?? null) ? $payload['registrosMeta'] : [];
        $groups = $this->groupScenarios($this->parse($payload), $registrosMeta);

        $group = $pesquisa->registro_tse !== null
            ? $groups->first(
                fn (array $group): bool => $group['registro'] === $pesquisa->registro_tse
                    && $group['cenario_id'] === $pesquisa->cenario_id,
            )
            // Sem registro_tse, a única chave estável que persistimos é o
            // próprio external_id (uuid determinístico da chave sintética) —
            // não há como relocalizar a pesquisa de outra forma.
            : $groups->first(
                fn (array $group): bool => $this->externalId($context, $group) === $pesquisa->external_id,
            );

        if ($group === null) {
            return null;
        }

        $eleicao = Eleicao::query()->find($pesquisa->eleicao_id);
        $candidateMap = $eleicao instanceof Eleicao ? $this->candidateMap($eleicao, $context) : null;

        return $this->buildResultadoColeta($context, $group, $candidateMap);
    }

    /**
     * Contextos padrão: Presidente nacional + Governador e Senador nas 27
     * UFs. Presidente por UF fica de fora do padrão (opcional — ver
     * {@see presidencialEstadualContexts()}), pra não multiplicar 27x uma
     * corrida que já é coberta pela visão nacional.
     *
     * @return list<ElectionContext>
     */
    public function defaultContexts(int $year): array
    {
        $contexts = [ElectionContext::presidenteNacional($year)];

        foreach (self::UFS as $uf) {
            $contexts[] = ElectionContext::governador($year, $uf);
        }

        foreach (self::UFS as $uf) {
            $contexts[] = ElectionContext::senador($year, $uf);
        }

        return $contexts;
    }

    /** @return list<ElectionContext> */
    public function presidencialEstadualContexts(int $year): array
    {
        return array_map(
            fn (string $uf): ElectionContext => ElectionContext::presidenteEstadual($year, $uf),
            self::UFS,
        );
    }

    /**
     * Busca, normaliza e faz upsert de UM contexto eleitoral. Idempotente:
     * pode rodar quantas vezes for preciso sem duplicar nada, porque a
     * identidade (external_id) é determinística a partir da chave natural
     * de cada cenário dentro do contexto.
     *
     * Ausência de pesquisas (payload `noData` ou HTTP 404) não é erro —
     * uma UF pode simplesmente não ter pesquisa publicada para o cargo.
     */
    public function syncContext(ElectionContext $context, ResultResolver $resultResolver): int
    {
        $payload = $this->fetch($context);

        if ($payload === null || ($payload['noData'] ?? false) === true) {
            Log::info('PollingData: nenhuma pesquisa publicada para este contexto.', [
                'context' => $context->label(),
                'url' => $context->sourceUrl(),
            ]);

            return 0;
        }

        if ($this->fingerprintUnchanged($context, $payload)) {
            Log::info('PollingData: fingerprint inalterado desde a última sincronização — nada a processar.', [
                'context' => $context->label(),
            ]);

            return 0;
        }

        $registrosMeta = is_array($payload['registrosMeta'] ?? null) ? $payload['registrosMeta'] : [];
        $groups = $this->groupScenarios($this->parse($payload), $registrosMeta);

        if ($groups->isEmpty()) {
            $this->rememberFingerprint($context, $payload);

            return 0;
        }

        $eleicao = Eleicao::query()->where('ano', $context->year)->first();

        if (! $eleicao instanceof Eleicao) {
            Log::warning('PollingData: eleição não cadastrada para o ano do contexto — sincronização ignorada.', [
                'context' => $context->label(),
            ]);

            return 0;
        }

        $candidateMap = $this->candidateMap($eleicao, $context);
        $processed = 0;
        $keptExternalIds = [];

        foreach ($groups as $group) {
            $externalId = $this->externalId($context, $group);
            $keptExternalIds[] = $externalId;

            $pesquisa = PesquisaEleitoral::query()->updateOrCreate(
                ['external_id' => $externalId],
                [
                    'eleicao_id' => $eleicao->id,
                    'external_election_id' => $this->externalElectionId($context),
                    'registro_tse' => $group['registro'],
                    'ano' => $context->year,
                    'uf' => $context->uf,
                    'municipio' => null,
                    'cargo' => $context->office,
                    'turno' => $context->round,
                    'cenario' => $group['tipo'] !== null
                        ? "{$group['tipo']}_{$context->round}t"
                        : "estimulado_{$context->round}t",
                    'cenario_id' => $group['cenario_id'],
                    'cenario_nome' => $group['cenario_nome'],
                    'instituto' => $group['instituto'],
                    'publicada_em' => $group['data'],
                    'coleta_inicio_em' => $group['coleta_inicio_em'],
                    'coleta_fim_em' => $group['coleta_fim_em'],
                    'tamanho_amostra' => $group['entrevistas'],
                    'margem_erro' => $group['margem_erro'],
                    'nivel_confianca' => $group['nivel_confianca'],
                    'metodologia' => $group['modo'],
                    'abrangencia' => $context->abrangencia(),
                    'tipo' => $group['tipo'],
                    'fonte_url' => $group['url'] !== null && $group['url'] !== ''
                        ? $group['url']
                        : $context->sourceUrl(),
                    'fonte_atualizada_em' => now(),
                ],
            );
            $processed++;

            $resultado = $this->buildResultadoColeta($context, $group, $candidateMap);
            $resultResolver->persist($pesquisa, $resultado);
            $processed += count($resultado->candidatos);
        }

        // Remove pesquisas que o PollingData não devolveu mais nesta
        // execução (corrigidas/despublicadas na fonte) — só dentro deste
        // MESMO contexto (cargo+uf+ano+turno) e só entre as que o
        // PollingData mesmo é dono; nunca toca em pesquisas do ElectioLab,
        // de curadoria manual, ou de outro contexto (ex.: sincronizar
        // Governador/CE nunca apaga Governador/SP).
        PesquisaEleitoral::query()
            ->where('origem_provider', 'pollingdata')
            ->where('cargo', $context->office)
            ->where('uf', $context->uf)
            ->where('ano', $context->year)
            ->where('turno', $context->round)
            ->whereNotIn('external_id', $keptExternalIds)
            ->delete();

        $this->rememberFingerprint($context, $payload);

        return $processed;
    }

    /**
     * Sincroniza vários contextos em sequência (nunca concorrente — o
     * PollingData é uma fonte pública sem SLA, e já vimos bloqueio de WAF em
     * outra fonte do projeto por comportamento agressivo). Uma falha num
     * contexto não interrompe os demais: fica registrada como `null` no
     * resultado e em log, e a sincronização continua.
     *
     * @param  list<ElectionContext>  $contexts
     * @return array<string, int|null> Contagem de registros processados por contexto (label), null quando o contexto falhou.
     */
    public function syncMany(array $contexts, ResultResolver $resultResolver): array
    {
        $results = [];
        $intervalMs = max(0, (int) config('services.pollingdata.interval_ms', 300));
        $total = count($contexts);

        foreach ($contexts as $index => $context) {
            try {
                $results[$context->label()] = $this->syncContext($context, $resultResolver);
            } catch (Throwable $exception) {
                Log::warning('PollingData: falha ao sincronizar um contexto — continuando com os demais.', [
                    'context' => $context->label(),
                    'exception' => $exception,
                ]);
                $results[$context->label()] = null;
            }

            if ($intervalMs > 0 && $index < $total - 1) {
                usleep($intervalMs * 1000);
            }
        }

        return $results;
    }

    public function syncQueuedRun(int $runId, ResultResolver $resultResolver): int
    {
        $run = SincronizacaoTse::query()->findOrFail($runId);
        $run->forceFill([
            'situacao' => 'processando',
            'erro' => null,
            'iniciada_em' => $run->iniciada_em ?? now(),
        ])->save();

        try {
            $results = $this->syncMany($this->defaultContexts($run->ano), $resultResolver);
            $succeeded = array_filter($results, fn (?int $value): bool => $value !== null);
            $failedLabels = array_keys(array_filter($results, fn (?int $value): bool => $value === null));

            if ($succeeded === [] && $failedLabels !== []) {
                throw new RuntimeException(
                    'Todos os contextos do PollingData falharam. Ver logs para detalhes: '.implode(', ', $failedLabels),
                );
            }

            $processed = array_sum($succeeded);

            $run->forceFill([
                'situacao' => 'concluida',
                'registros_processados' => $processed,
                'erro' => $failedLabels !== []
                    ? Str::limit('Alguns contextos falharam (ver logs): '.implode(', ', $failedLabels), 10000)
                    : null,
                'concluida_em' => now(),
            ])->save();

            return $processed;
        } catch (Throwable $exception) {
            $run->forceFill([
                'situacao' => 'falhou',
                'erro' => mb_substr($exception->getMessage(), 0, 10000),
                'concluida_em' => now(),
            ])->save();

            throw $exception;
        }
    }

    /** @return array<string, mixed>|null null quando o contexto não tem pesquisas (404 ou `noData`). */
    private function fetch(ElectionContext $context): ?array
    {
        if (array_key_exists($context->label(), $this->cachedPayloads)) {
            return $this->cachedPayloads[$context->label()];
        }

        Log::info('Consultando PollingData.', [
            'context' => $context->label(),
            'url' => $context->sourceUrl(),
        ]);

        try {
            $response = $this->request()->get((string) config('services.pollingdata.url'), [
                'url' => $context->sourceUrl(),
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                "Falha de conexão ao consultar o PollingData para {$context->label()}: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if ($response->status() === 404) {
            Log::info('PollingData: contexto sem pesquisas publicadas (404).', [
                'context' => $context->label(),
                'url' => $context->sourceUrl(),
            ]);

            return $this->cachedPayloads[$context->label()] = null;
        }

        if ($response->failed()) {
            Log::warning('Falha ao consultar o PollingData.', [
                'context' => $context->label(),
                'url' => $context->sourceUrl(),
                'status' => $response->status(),
            ]);

            throw new RuntimeException(sprintf(
                'Falha ao consultar PollingData para %s. HTTP %d. URL: %s',
                $context->label(),
                $response->status(),
                $context->sourceUrl(),
            ));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException("O PollingData retornou uma resposta inválida para {$context->label()}.");
        }

        return $this->cachedPayloads[$context->label()] = $payload;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withUserAgent((string) config('services.pollingdata.user_agent'))
            ->connectTimeout(15)
            ->timeout((int) config('services.pollingdata.timeout', 30))
            ->retry(
                3,
                function (int $attempt, Throwable $exception): int {
                    // Respeita Retry-After quando o servidor informar (429).
                    if ($exception instanceof RequestException) {
                        $retryAfter = $exception->response->header('Retry-After');

                        if ($exception->response->status() === 429 && is_numeric($retryAfter)) {
                            return ((int) $retryAfter) * 1000;
                        }
                    }

                    return $attempt * 500;
                },
                when: function (Throwable $exception): bool {
                    // Só vale re-tentar erro transitório (limite de taxa ou
                    // instabilidade do servidor) — 403/404/outros 4xx são
                    // permanentes para a mesma URL, tentar de novo é
                    // desperdício e agressivo com uma fonte pública sem SLA.
                    if (! $exception instanceof RequestException) {
                        return true;
                    }

                    $status = $exception->response->status();

                    return $status === 429 || $status >= 500;
                },
                throw: false,
            );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function parse(array $payload): array
    {
        $rows = $payload['tabPesquisas'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            fn (mixed $row): bool => is_array($row)
                && ! empty($row['instituto'])
                && ! empty($row['data'])
                && isset($row['cenarioId'])
                && ! empty($row['candidato'])
                && is_numeric($row['voto'] ?? null),
        ));
    }

    /**
     * Agrupa as linhas cruas por (chave da pesquisa, cenarioId) — nunca por
     * cenarioId sozinho, que só é único dentro da pesquisa dona (e, como
     * confirmado na auditoria, nem sempre único por cargo dentro do mesmo
     * registro — daí o cargo entrar na identidade em {@see externalId()}).
     * cargo/UF/turno/ano NÃO são derivados aqui: vêm do ElectionContext que
     * já determinou qual URL foi consultada.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $registrosMeta
     * @return Collection<string, array<string, mixed>>
     */
    private function groupScenarios(array $rows, array $registrosMeta): Collection
    {
        $groups = collect();

        foreach ($rows as $row) {
            $registro = $this->nullableString($row['registro'] ?? null);
            $cenarioId = (int) $row['cenarioId'];
            $pollKey = $registro ?? $this->syntheticPollKey($row);
            $groupKey = "{$pollKey}::{$cenarioId}";

            if (! $groups->has($groupKey)) {
                [$instituto, $modo] = $this->splitInstituto((string) $row['instituto']);
                $cenarioNome = $this->nullableString($row['cenarioNome'] ?? null);
                $meta = $registro !== null ? ($registrosMeta[$registro] ?? null) : null;
                $meta = is_array($meta) ? $meta : null;

                $groups->put($groupKey, [
                    'registro' => $registro,
                    'poll_key' => $pollKey,
                    'cenario_id' => $cenarioId,
                    'cenario_nome' => $cenarioNome,
                    'tipo' => $this->deriveTipo($cenarioNome),
                    'instituto' => $instituto,
                    'modo' => $modo !== '' ? $modo : null,
                    'data' => (string) $row['data'],
                    'coleta_inicio_em' => $meta['dataInicio'] ?? null,
                    'coleta_fim_em' => $meta['dataFim'] ?? null,
                    'entrevistas' => isset($row['entrevistas']) ? (int) $row['entrevistas'] : null,
                    'margem_erro' => $this->normalizePercent($row['erro'] ?? null),
                    'nivel_confianca' => $this->normalizePercent($row['confianca'] ?? null),
                    'url' => $this->nullableString($row['url'] ?? null),
                    'cnpj_instituto' => $meta['cnpjInstituto'] ?? null,
                    'cnpj_contratante' => $meta['cnpjContratante'] ?? null,
                    'data_arquivamento' => $this->nullableString($row['dataArquivamento'] ?? null),
                    'candidatos' => [],
                ]);
            }

            $group = $groups->get($groupKey);
            $isNaoValido = ($row['nv'] ?? null) !== null;
            $group['candidatos'][] = [
                'candidato' => (string) $row['candidato'],
                'partido' => $this->nullableString($row['partido'] ?? null),
                'voto' => (float) $row['voto'],
                'nao_valido' => $isNaoValido,
            ];
            $groups->put($groupKey, $group);
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  ?Collection<string, CandidatoPolitico>  $candidateMap
     */
    private function buildResultadoColeta(ElectionContext $context, array $group, ?Collection $candidateMap = null): ResultadoColeta
    {
        $externalId = $this->externalId($context, $group);
        $candidatos = [];

        foreach ($group['candidatos'] as $candidato) {
            $nome = $candidato['nao_valido']
                ? $candidato['candidato']
                : $this->stripParty($candidato['candidato']);

            $candidatos[] = [
                'external_candidate_id' => $this->deterministicUuid("{$externalId}|{$candidato['candidato']}"),
                'candidato_politico_id' => ! $candidato['nao_valido'] && $candidateMap !== null
                    ? $this->matchCandidate($candidateMap, $nome, $candidato['partido'])?->id
                    : null,
                'nome' => $nome,
                'partido' => $candidato['partido'],
                'percentual' => $candidato['voto'],
                'nao_valido' => $candidato['nao_valido'],
            ];
        }

        return new ResultadoColeta(
            provider: 'pollingdata',
            tipo: 'pollingdata',
            confidenceScore: $group['registro'] !== null
                ? self::CONFIDENCE_WITH_REGISTRO
                : self::CONFIDENCE_WITHOUT_REGISTRO,
            candidatos: $candidatos,
            url: $group['url'] ?? $context->sourceUrl(),
            metadata: [
                'registro' => $group['registro'],
                'cnpj_instituto' => $group['cnpj_instituto'],
                'cnpj_contratante' => $group['cnpj_contratante'],
                'data_arquivamento' => $group['data_arquivamento'],
                'pollingdata_context' => $context->label(),
            ],
        );
    }

    /**
     * cargo entra na chave porque um mesmo registro pode conter cenários de
     * mais de um cargo com o MESMO cenarioId (auditado — ver docblock da
     * classe); sem isso, sincronizar Governador e Senador do mesmo estado
     * poderia colidir e misturar os dois numa só pesquisa_eleitoral.
     *
     * @param  array<string, mixed>  $group
     */
    private function externalId(ElectionContext $context, array $group): string
    {
        return $this->deterministicUuid("{$context->office}|{$group['poll_key']}|{$group['cenario_id']}");
    }

    private function externalElectionId(ElectionContext $context): string
    {
        return $this->deterministicUuid("election|{$context->office}|{$context->uf}|{$context->year}|t{$context->round}");
    }

    private function deterministicUuid(string $naturalKey): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, self::UUID_NAMESPACE.'|'.$naturalKey)->toString();
    }

    /** @param array<string, mixed> $row */
    private function syntheticPollKey(array $row): string
    {
        return 'sem-registro:'.hash('sha1', implode('|', [
            $row['instituto'] ?? '',
            $row['data'] ?? '',
            $row['dataArquivamento'] ?? '',
            $row['entrevistas'] ?? '',
        ]));
    }

    /** @return array{0: string, 1: string} [instituto, modo] */
    private function splitInstituto(string $raw): array
    {
        if (! str_contains($raw, '<>')) {
            return [trim($raw), ''];
        }

        [$instituto, $modo] = array_pad(explode('<>', $raw, 2), 2, '');

        return [trim($instituto), trim($modo)];
    }

    /**
     * Regra documentada (não é garantia da fonte, é inferência tolerante):
     * procura "espontânea"/"estimulada"/"avaliação|aprovação|rejeição" no
     * texto livre do cenário. Não encontrando nenhum marcador, devolve null
     * em vez de arriscar um chute. Diferente de cargo/UF/turno/ano (que
     * agora vêm do ElectionContext), `tipo` não tem equivalente no contexto
     * — continua sendo o único campo inferido do texto livre.
     */
    private function deriveTipo(?string $cenarioNome): ?string
    {
        if ($cenarioNome === null) {
            return null;
        }

        if (preg_match('/espont[aâ]nea/ui', $cenarioNome) === 1) {
            return 'espontanea';
        }

        if (preg_match('/estimulad[ao]/ui', $cenarioNome) === 1) {
            return 'estimulada';
        }

        if (preg_match('/avalia[cç][aã]o|aprova[cç][aã]o|rejei[cç][aã]o/ui', $cenarioNome) === 1) {
            return 'avaliacao';
        }

        return null;
    }

    /** /presidente/br usa t1_todas (não há /t1 nacional) — os demais contextos usam t1. */
    private function canonicalSlug(string $office, string $uf): string
    {
        return $office === 'presidente' && $uf === 'BR' ? 't1_todas' : 't1';
    }

    private function fingerprintCacheKey(ElectionContext $context): string
    {
        return 'pollingdata:fingerprint:'.$context->label();
    }

    /** @param array<string, mixed> $payload */
    private function fingerprintUnchanged(ElectionContext $context, array $payload): bool
    {
        if (! isset($payload['_fingerprint'])) {
            return false;
        }

        $cached = Cache::get($this->fingerprintCacheKey($context));

        return $cached !== null && $cached === $payload['_fingerprint'];
    }

    /** @param array<string, mixed> $payload */
    private function rememberFingerprint(ElectionContext $context, array $payload): void
    {
        if (! isset($payload['_fingerprint'])) {
            return;
        }

        Cache::put($this->fingerprintCacheKey($context), $payload['_fingerprint'], now()->addDays(7));
    }

    private function normalizePercent(mixed $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $normalized = str_replace(['%', ',', ' '], ['', '.', ''], (string) $raw);

        return is_numeric($normalized) ? round((float) $normalized, 2) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /** "Lula (PT)" -> "Lula" — mesmo padrão usado no importador de cenário customizado do próprio PollingData. */
    private function stripParty(string $candidato): string
    {
        if (preg_match('/^(.+?)\s*\([^)]+\)$/u', $candidato, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($candidato);
    }

    /** @return Collection<string, CandidatoPolitico> */
    private function candidateMap(Eleicao $eleicao, ElectionContext $context): Collection
    {
        $query = CandidatoPolitico::query()->where('eleicao_id', $eleicao->id);

        match ($context->office) {
            'presidente' => $query->where('abrangencia', CandidateScope::National)->where('cargo', 'Presidente'),
            'governador' => $query->where('abrangencia', CandidateScope::State)
                ->where('cargo', 'Governador')
                ->where('uf', $context->uf),
            'senador' => $query->where('abrangencia', CandidateScope::State)
                ->where('cargo', 'Senador')
                ->where('uf', $context->uf),
            default => $query->whereRaw('1 = 0'),
        };

        $map = collect();

        foreach ($query->get() as $candidate) {
            $party = $this->normalize($candidate->partido_sigla ?? '');

            foreach ([$candidate->nome_urna, $candidate->nome] as $name) {
                $normalizedName = $this->normalize($name);

                if ($party !== '' && ! $map->has("{$normalizedName}|{$party}")) {
                    $map->put("{$normalizedName}|{$party}", $candidate);
                }

                if (! $map->has($normalizedName)) {
                    $map->put($normalizedName, $candidate);
                }
            }
        }

        return $map;
    }

    /** @param Collection<string, CandidatoPolitico> $candidateMap */
    private function matchCandidate(Collection $candidateMap, string $nome, ?string $partido): ?CandidatoPolitico
    {
        $normalizedName = $this->normalize($nome);
        $normalizedParty = $this->normalize($partido ?? '');

        if ($normalizedParty !== '' && $candidateMap->has("{$normalizedName}|{$normalizedParty}")) {
            return $candidateMap->get("{$normalizedName}|{$normalizedParty}");
        }

        return $candidateMap->get($normalizedName);
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(Str::ascii($value))) ?? '';
    }
}

<?php

namespace App\Services\Politics\Tse;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Client HTTP pra GOVNEX API, de onde vêm todos os datasets do TSE que a
 * plataforma sincroniza (TsePoliticalDataSyncService::DATASETS).
 *
 * A localização segue a convenção de nome de GovnexApiDatasetCatalog, em
 * dois formatos de publicação:
 *  - recorte único (ex.: municipio-tse-ibge, consulta-cand-2024), resolvido
 *    por `locate()`; e
 *  - um dataset por UF (ex.: perfil-eleitorado-2026-ce), varridos de uma vez
 *    por `locateByUf()`, que devolve só as UFs já publicadas.
 *
 * Toda falha vira RuntimeException com uma mensagem para quem opera a tela:
 * ela é gravada na sincronização e aparece no status, então nunca pode ser o
 * corpo cru da resposta.
 */
class GovnexApiClient
{
    /** Status de importação da GOVNEX API enquanto o arquivo ainda está sendo processado. */
    private const IMPORTING_STATUSES = ['pending', 'downloading', 'downloaded', 'extracting', 'importing'];

    /**
     * Linhas por página. A GOVNEX API corta o per_page em 2.000 para cliente
     * identificado (header X-Api-Key) e em 100 para anônimo, sem avisar —
     * pedir acima do teto só geraria mais requisições, e requisição a mais é
     * o que encosta no limite por minuto.
     *
     * Ficamos abaixo do teto porque um `artisan serve` do outro lado entrega
     * respostas cortadas de vez em quando. Medido contra a votação por seção
     * do CE: acontece de ~90KB pra cima, em torno de 1 a cada 3 páginas, sem
     * depender do tamanho exato (1.000, 500, 400 e 125 linhas falharam na
     * mesma proporção; só respostas de poucos KB nunca falharam). Quem
     * repete a página é fetchPage(); páginas maiores só significam menos
     * páginas. Atrás de um servidor web de verdade dá pra subir em
     * `services.tse.records_per_page`.
     */
    private const IDENTIFIED_PER_PAGE = 1000;

    private const ANONYMOUS_PER_PAGE = 100;

    /** Releituras de uma página que chegou cortada, antes de desistir. */
    private const TRUNCATED_PAGE_ATTEMPTS = 3;

    private readonly string $baseUrl;

    private readonly ?string $apiKey;

    public function __construct(GovnexApiSettings $settings)
    {
        $this->baseUrl = $settings->url();
        $this->apiKey = $settings->key();
    }

    /**
     * Localiza um dataset pela convenção de nome (ver
     * GovnexApiDatasetCatalog): procura primeiro sob a fonte `tse`, onde os
     * dados do TSE devem ser cadastrados, e só então varre as demais fontes
     * do catálogo — a base de municípios já esteve publicada sob `govnex`, e
     * uma remoção de dado por reorganização do catálogo do outro lado não
     * deveria derrubar a sincronização daqui.
     *
     * Só entra dataset com o último import concluído: um dataset pode existir
     * no catálogo sem dado nenhum ainda, ou com o último import falho. Quando
     * não acha, unavailableMessage() explica o motivo.
     *
     * @return array{source: string, slug: string, filterable: list<string>}|null
     */
    public function locate(string $dataset, ?int $year = null, ?string $uf = null): ?array
    {
        $slug = GovnexApiDatasetCatalog::slug($dataset, $year, $uf);

        foreach ($this->searchOrder() as $source) {
            foreach ($this->datasetsOf($source) as $candidate) {
                if (($candidate['slug'] ?? null) === $slug && $this->isImported($candidate)) {
                    return $this->located($source, $slug, $candidate);
                }
            }
        }

        return null;
    }

    /**
     * UFs já publicadas de um dataset por UF naquele ano, pelo prefixo do
     * slug (ex.: perfil-eleitorado-2026-ce). Cresce sozinho conforme mais
     * estados forem subidos na GOVNEX API, sem mudar código nem
     * configuração aqui — e sem exigir o Brasil inteiro de uma vez.
     *
     * @return array<string, array{source: string, slug: string, filterable: list<string>}> UF => localização
     */
    public function locateByUf(string $dataset, int $year): array
    {
        $found = [];

        foreach ($this->searchOrder() as $source) {
            foreach ($this->datasetsOf($source) as $candidate) {
                $slug = $candidate['slug'] ?? null;

                if (! is_string($slug) || ! $this->isImported($candidate)) {
                    continue;
                }

                $uf = GovnexApiDatasetCatalog::ufFromSlug($dataset, $year, $slug);

                if ($uf !== null && ! isset($found[$uf])) {
                    $found[$uf] = $this->located($source, $slug, $candidate);
                }
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Por que locate() não achou o dataset, dito para quem vai resolver do
     * outro lado: não cadastrado, cadastrado sem arquivo, importação em
     * andamento ou importação que falhou.
     */
    public function unavailableMessage(string $dataset, ?int $year = null): string
    {
        $slug = GovnexApiDatasetCatalog::slug($dataset, $year);
        $candidate = null;

        foreach ($this->searchOrder() as $source) {
            foreach ($this->datasetsOf($source) as $item) {
                if (($item['slug'] ?? null) === $slug) {
                    $candidate = $item;

                    break 2;
                }
            }
        }

        if ($candidate === null) {
            return "O dataset {$slug} não está cadastrado na GOVNEX API. Cadastre-o na fonte TSE com esse nome e importe nele o arquivo do TSE.";
        }

        $status = $candidate['latest_import_status'] ?? null;

        return match (true) {
            $status === null => $this->notImportedMessage($slug),
            $status === 'failed' => "A última importação do dataset {$slug} na GOVNEX API falhou. Refaça a importação por lá e sincronize de novo.",
            in_array($status, self::IMPORTING_STATUSES, true) => "O dataset {$slug} ainda está sendo importado na GOVNEX API. Aguarde a importação terminar e sincronize de novo.",
            default => "O dataset {$slug} não pôde ser lido na GOVNEX API. Sincronize de novo; se o problema continuar, confira o dataset por lá.",
        };
    }

    /**
     * Localização de um dataset junto dos campos que ele aceita como filtro.
     * É o que permite pedir só as linhas que interessam em vez de ler o
     * dataset inteiro (ver
     * TsePoliticalDataSyncService::importSectionVotesDataset()); a GOVNEX API
     * recusa filtro em coluna não declarada.
     *
     * @param  array<string, mixed>  $dataset
     * @return array{source: string, slug: string, filterable: list<string>}
     */
    private function located(string $source, string $slug, array $dataset): array
    {
        $filterable = $dataset['filterable_fields'] ?? [];

        return [
            'source' => $source,
            'slug' => $slug,
            'filterable' => is_array($filterable)
                ? array_values(array_filter($filterable, 'is_string'))
                : [],
        ];
    }

    /**
     * Fonte esperada primeiro, demais depois — sem repetir a `tse` na volta.
     *
     * @return list<string>
     */
    private function searchOrder(): array
    {
        $sources = $this->sources();
        $rest = array_values(array_filter(
            $sources,
            fn (string $slug): bool => $slug !== GovnexApiDatasetCatalog::SOURCE,
        ));

        return [GovnexApiDatasetCatalog::SOURCE, ...$rest];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function datasetsOf(string $source): array
    {
        $response = $this->send("{$this->baseUrl}/sources/{$source}/datasets");

        // Uma fonte listada no catálogo pode não responder (ex.: removida
        // entre a listagem e esta chamada) — nesse caso ela apenas não
        // contribui com datasets, em vez de derrubar a busca inteira.
        if ($response->status() === 404) {
            return [];
        }

        $this->ensureSuccessful($response);

        return array_values(array_filter(
            $response->json('data', []),
            fn ($dataset): bool => is_array($dataset),
        ));
    }

    /**
     * `latest_import_status` nulo quer dizer que o dataset foi cadastrado mas
     * nunca recebeu arquivo — os registros dele respondem 409. Só a chave
     * ausente (versão da API anterior ao campo) continua valendo como
     * importado.
     *
     * @param  array<string, mixed>  $dataset
     */
    private function isImported(array $dataset): bool
    {
        if (! array_key_exists('latest_import_status', $dataset)) {
            return true;
        }

        return $dataset['latest_import_status'] === 'completed';
    }

    /**
     * Slugs das fontes publicadas no catalogo.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $response = $this->ensureSuccessful($this->send("{$this->baseUrl}/sources"));
        $slugs = [];

        foreach ($response->json('data', []) as $source) {
            $slug = is_array($source) ? ($source['slug'] ?? null) : null;

            if (is_string($slug) && $slug !== '') {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Total de linhas do dataset, pela paginação comum (que expõe
     * meta.total_records) pedindo uma linha só — o modo cursor de
     * eachRecord() não informa total. Serve só ao percentual de progresso:
     * devolve 0 quando a API não informar.
     *
     * @param  array<string, string>  $filters  ver eachRecord()
     */
    public function count(string $source, string $datasetSlug, array $filters = []): int
    {
        $response = $this->ensureSuccessful(
            $this->send(
                "{$this->baseUrl}/sources/{$source}/datasets/{$datasetSlug}/records",
                ['per_page' => 1, ...$filters],
            ),
            $datasetSlug,
        );

        return (int) ($response->json('meta.total_records') ?? 0);
    }

    /**
     * Percorre todas as páginas de um dataset via paginação por cursor
     * (?paginate=cursor), chamando $callback pra cada linha — evita carregar
     * o dataset inteiro (centenas de milhares de linhas por UF) em memória
     * de uma vez, e evita o custo de COUNT(*) que a paginação por página
     * paga a cada requisição do lado da GOVNEX API.
     *
     * @param  callable(array<string, string>): void  $callback
     * @param  array<string, string>  $filters  Coluna => valor, só de colunas
     *                                          que o dataset declara como
     *                                          filtráveis (ver locate()); a
     *                                          GOVNEX API recusa qualquer
     *                                          outra. O link da próxima
     *                                          página já volta com o filtro
     *                                          embutido.
     *
     * @param-immediately-invoked-callable $callback
     */
    public function eachRecord(
        string $source,
        string $datasetSlug,
        callable $callback,
        ?int $perPage = null,
        array $filters = [],
    ): void {
        $url = "{$this->baseUrl}/sources/{$source}/datasets/{$datasetSlug}/records";
        $query = ['paginate' => 'cursor', 'per_page' => $perPage ?? $this->recordsPerPage(), ...$filters];

        while ($url !== null) {
            // Só a 1ª chamada leva $query: a partir da 2ª, $url já vem com a
            // query inteira embutida (inclusive o cursor) no link 'next' da
            // resposta anterior — ver send().
            $page = $this->fetchPage($url, $query, $datasetSlug);

            foreach ($page['rows'] as $row) {
                $callback($row);
            }

            $url = $page['next'];
            $query = null;
        }
    }

    /**
     * Uma página de registros já validada. Corpo que não fecha como JSON é
     * tratado como falha de transporte, não como formato inválido: contra um
     * `artisan serve` a resposta chega cortada de vez em quando (ver
     * IDENTIFIED_PER_PAGE), e reler a mesma página resolve. Desistir de cara
     * derrubaria um sync de centenas de páginas por um soluço do servidor.
     *
     * @param  array<string, mixed>|null  $query
     * @return array{rows: array<int, mixed>, next: string|null}
     */
    private function fetchPage(string $url, ?array $query, string $datasetSlug): array
    {
        for ($attempt = 1; ; $attempt++) {
            $payload = $this->ensureSuccessful($this->send($url, $query), $datasetSlug)->json();

            if (is_array($payload) && is_array($payload['data'] ?? null)) {
                $next = $payload['links']['next'] ?? null;

                return ['rows' => $payload['data'], 'next' => is_string($next) ? $next : null];
            }

            if ($attempt >= self::TRUNCATED_PAGE_ATTEMPTS) {
                throw new RuntimeException(
                    "A GOVNEX API respondeu num formato inesperado ao ler o dataset {$datasetSlug}: a resposta chegou incompleta {$attempt} vezes seguidas.",
                );
            }

            Sleep::for(2)->seconds();
        }
    }

    /**
     * GET na GOVNEX API. Falha de conexão vira mensagem legível aqui mesmo;
     * resposta de erro fica para ensureSuccessful(), depois de o chamador ter
     * a chance de aceitar algum status (ver datasetsOf()).
     *
     * @param  array<string, mixed>|null  $query
     */
    private function send(string $url, ?array $query = null): Response
    {
        try {
            // Sem $query, get() recebe um único argumento de propósito:
            // PendingRequest::get() manda 'query' => [] pro Guzzle sempre que
            // recebe um 2º argumento, MESMO vazio — o que apaga a query já
            // embutida na URL (inclusive o cursor) e faz a paginação girar
            // pra sempre na mesma página.
            return $query !== null
                ? $this->request()->get($url, $query)
                : $this->request()->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException(
                "Não foi possível conectar à GOVNEX API em {$this->baseUrl}. Confira se ela está no ar e se GOVNEX_API_URL aponta para o endereço certo.",
            );
        }
    }

    private function ensureSuccessful(Response $response, ?string $datasetSlug = null): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        $target = $datasetSlug !== null ? "dataset {$datasetSlug}" : 'catálogo de datasets';

        throw new RuntimeException(match (true) {
            $status === 409 && $datasetSlug !== null => $this->notImportedMessage($datasetSlug),
            $status === 404 => "A GOVNEX API não encontrou o {$target}. Confira se ele continua cadastrado com esse nome.",
            $status === 401 || $status === 403 => 'A GOVNEX API recusou o acesso. Confira a chave configurada em GOVNEX_API_KEY.',
            $status === 429 => 'A GOVNEX API limitou o número de consultas. Aguarde alguns minutos e sincronize de novo — com GOVNEX_API_KEY configurada, o limite é maior.',
            $response->serverError() => "A GOVNEX API teve um erro interno ao consultar o {$target} (HTTP {$status}). Tente de novo em alguns minutos.",
            default => $this->rejectedMessage($response, $target),
        });
    }

    /** Status sem tratamento próprio: diz qual foi e o motivo que a API deu, sem o resto do corpo. */
    private function rejectedMessage(Response $response, string $target): string
    {
        $message = "A GOVNEX API recusou a consulta ao {$target} (HTTP {$response->status()}).";
        $reason = $response->json('message');

        return is_string($reason) && $reason !== ''
            ? "{$message} Motivo informado: ".Str::limit($reason, 200)
            : $message;
    }

    private function notImportedMessage(string $slug): string
    {
        return "O dataset {$slug} está cadastrado na GOVNEX API, mas ainda não tem nenhum arquivo importado. Importe nele o arquivo do TSE e sincronize de novo.";
    }

    /** Ver IDENTIFIED_PER_PAGE: o teto depende de a API reconhecer a chave. */
    private function recordsPerPage(): int
    {
        $configured = config('services.tse.records_per_page');

        if (filled($configured)) {
            return max(1, (int) $configured);
        }

        return filled($this->apiKey) ? self::IDENTIFIED_PER_PAGE : self::ANONYMOUS_PER_PAGE;
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout((int) config('services.tse.timeout', 600))
            // Só vale repetir o que pode passar sozinho — queda de conexão,
            // erro 5xx e o limite por minuto. Um 404 ou 409 repetido daria a
            // mesma resposta.
            ->retry(
                3,
                // Ler um dataset grande encosta no limite por minuto mais de
                // uma vez: o 429 vem com Retry-After, e esperar o que a API
                // pediu é o que faz o sync atravessar em vez de falhar no
                // meio. Nas demais falhas, espera crescente.
                function (int $attempt, Throwable $exception): int {
                    $retryAfter = $exception instanceof RequestException
                        ? (int) $exception->response->header('Retry-After')
                        : 0;

                    return $retryAfter > 0
                        ? min($retryAfter, 120) * 1000
                        : 2000 * $attempt;
                },
                fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException
                        && ($exception->response->status() === 429 || $exception->response->serverError())),
                throw: false,
            );

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withHeaders(['X-Api-Key' => $this->apiKey]);
        }

        return $request;
    }
}

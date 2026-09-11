<?php

namespace App\Services\Politics\Tse;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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
     * @return array{source: string, slug: string}|null
     */
    public function locate(string $dataset, ?int $year = null, ?string $uf = null): ?array
    {
        $slug = GovnexApiDatasetCatalog::slug($dataset, $year, $uf);

        foreach ($this->searchOrder() as $source) {
            foreach ($this->datasetsOf($source) as $candidate) {
                if (($candidate['slug'] ?? null) === $slug && $this->isImported($candidate)) {
                    return ['source' => $source, 'slug' => $slug];
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
     * @return array<string, array{source: string, slug: string}> UF => localização
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
                    $found[$uf] = ['source' => $source, 'slug' => $slug];
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
     */
    public function count(string $source, string $datasetSlug): int
    {
        $response = $this->ensureSuccessful(
            $this->send("{$this->baseUrl}/sources/{$source}/datasets/{$datasetSlug}/records", ['per_page' => 1]),
            $datasetSlug,
        );

        return (int) ($response->json('meta.total_records') ?? 0);
    }

    /**
     * O tamanho de página é conservador de propósito: contra um `artisan
     * serve` (API em desenvolvimento), respostas acima de ~500KB penduram o
     * cliente HTTP do Laravel por minutos — 500 linhas por página mantêm a
     * resposta na casa dos 125KB e o sync inteiro em segundos. Atrás de um
     * servidor web de verdade dá pra subir esse número.
     *
     * Percorre todas as páginas de um dataset via paginação por cursor
     * (?paginate=cursor), chamando $callback pra cada linha — evita carregar
     * o dataset inteiro (centenas de milhares de linhas por UF) em memória
     * de uma vez, e evita o custo de COUNT(*) que a paginação por página
     * paga a cada requisição do lado da GOVNEX API.
     *
     * @param  callable(array<string, string>): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    public function eachRecord(
        string $source,
        string $datasetSlug,
        callable $callback,
        int $perPage = 500,
    ): void {
        $url = "{$this->baseUrl}/sources/{$source}/datasets/{$datasetSlug}/records";
        $query = ['paginate' => 'cursor', 'per_page' => $perPage];

        while ($url !== null) {
            // Só a 1ª chamada leva $query: a partir da 2ª, $url já vem com a
            // query inteira embutida (inclusive o cursor) no link 'next' da
            // resposta anterior — ver send().
            $response = $this->ensureSuccessful($this->send($url, $query), $datasetSlug);
            $payload = $response->json();

            if (! is_array($payload['data'] ?? null)) {
                throw new RuntimeException("A GOVNEX API respondeu num formato inesperado ao ler o dataset {$datasetSlug}.");
            }

            foreach ($payload['data'] as $row) {
                $callback($row);
            }

            $url = $payload['links']['next'] ?? null;
            $query = null;
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

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout((int) config('services.tse.timeout', 600))
            // Só vale repetir o que pode passar sozinho — queda de conexão e
            // erro 5xx. Um 404, 409 ou 429 repetido daria a mesma resposta.
            ->retry(
                3,
                2000,
                fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            );

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withHeaders(['X-Api-Key' => $this->apiKey]);
        }

        return $request;
    }
}

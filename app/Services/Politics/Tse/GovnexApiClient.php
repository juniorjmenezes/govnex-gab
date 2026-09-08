<?php

namespace App\Services\Politics\Tse;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client HTTP pra GOVNEX API — hoje só usado pelo eleitorado
 * (TsePoliticalDataSyncService::importElectorate()), que passou a puxar os
 * dados de lá em vez de parsear o ZIP oficial do TSE. A GOVNEX API publica
 * um dataset "Perfil eleitorado" por UF (ex.: perfil-eleitorado-ce-2026);
 * este client descobre quais UFs já foram publicadas pra um ano e percorre
 * cada uma, sem precisar saber de antemão quais estados já foram
 * importados do lado de lá.
 */
class GovnexApiClient
{
    private readonly string $baseUrl;

    private readonly ?string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.govnex_api.url'), '/');
        $this->apiKey = config('services.govnex_api.key');
    }

    /**
     * Descobre quais datasets "Perfil eleitorado" (um por UF) já estão
     * prontos pra uso pra esse ano — via metadata.uf, não pelo texto do
     * slug (que já saiu inconsistente: "perfil-eleitorado-ce-2026" vs
     * "perfil-eleitorado-bahia-2026" pro mesmo tipo de dataset). Um
     * dataset só entra no resultado se:
     *  - metadata.uf estiver marcado (a GOVNEX API só marca isso quando o
     *    CSV importado trouxe uma única UF em SG_UF — ver
     *    CsvStreamImporter::tagDatasetUf() do lado de lá); e
     *  - o último import tiver terminado (latest_import_status ===
     *    'completed') — um dataset pode existir no catálogo sem dado
     *    nenhum ainda, ou com o último import falho/em andamento.
     * Cresce conforme mais estados forem subidos na GOVNEX API, sem
     * precisar mudar código nem configuração aqui.
     *
     * @return array<string, string> UF (sigla) => slug do dataset
     */
    public function electorateDatasets(int $year): array
    {
        $response = $this->request()->get("{$this->baseUrl}/sources/tse/datasets");
        $response->throw();

        $datasets = $response->json('data', []);
        $bySlug = [];

        foreach (is_array($datasets) ? $datasets : [] as $dataset) {
            if (! is_array($dataset) || ($dataset['year'] ?? null) !== $year) {
                continue;
            }

            $slug = $dataset['slug'] ?? null;
            $uf = is_array($dataset['metadata'] ?? null) ? ($dataset['metadata']['uf'] ?? null) : null;
            $status = $dataset['latest_import_status'] ?? null;

            if (is_string($slug) && is_string($uf) && $uf !== '' && $status === 'completed') {
                $bySlug[mb_strtoupper($uf)] = $slug;
            }
        }

        return $bySlug;
    }

    /**
     * Percorre todas as páginas de um dataset via paginação por cursor
     * (?paginate=cursor), chamando $callback pra cada linha — evita carregar
     * o dataset inteiro (centenas de milhares de linhas por UF) em memória
     * de uma vez, e evita o custo de COUNT(*) que a paginação por página
     * paga a cada requisição do lado da GOVNEX API.
     *
     * @param  callable(array<string, string>): void  $callback
     */
    public function eachRecord(string $datasetSlug, callable $callback): void
    {
        $url = "{$this->baseUrl}/sources/tse/datasets/{$datasetSlug}/records";
        $query = ['paginate' => 'cursor', 'per_page' => 2000];

        while ($url !== null) {
            // Só a 1ª chamada passa $query pro get(): a partir da 2ª, $url
            // já vem com a query inteira embutida (inclusive o cursor) no
            // link 'next' da resposta anterior. PendingRequest::get() manda
            // 'query' => [] pro Guzzle sempre que recebe um 2º argumento,
            // MESMO vazio — o que sobrescreve (apaga) a query já embutida
            // na URL, descartando o cursor e fazendo a paginação girar pra
            // sempre na mesma página. Chamar get($url) com um único
            // argumento evita esse `'query' => []` e preserva o cursor.
            $response = $query !== null
                ? $this->request()->get($url, $query)
                : $this->request()->get($url);
            $response->throw();
            $payload = $response->json();

            if (! is_array($payload['data'] ?? null)) {
                throw new RuntimeException("Resposta inesperada da GOVNEX API para o dataset {$datasetSlug}.");
            }

            foreach ($payload['data'] as $row) {
                $callback($row);
            }

            $url = $payload['links']['next'] ?? null;
            $query = null;
        }
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout((int) config('services.tse.timeout', 600))
            ->retry(3, 2000);

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withHeaders(['X-Api-Key' => $this->apiKey]);
        }

        return $request;
    }
}

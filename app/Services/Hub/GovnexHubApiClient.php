<?php

namespace App\Services\Hub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Leitura da API do Govnex Hub.
 *
 * Só existe um endpoint interessante aqui hoje: o acesso efetivo de uma pessoa
 * num sistema. Vínculo e papel não viajam em claim do `id_token` porque mudam
 * mais rápido do que um token vive — quem precisa deles pergunta o estado de
 * agora, no login.
 *
 * A autenticação é `Bearer <client_secret do Sistema>` — o mesmo segredo que
 * assina o webhook que chega aqui (`HubCallbackSignatureValidator`). Um segredo
 * por sistema, dois usos.
 */
class GovnexHubApiClient
{
    /**
     * Acesso de uma pessoa no sistema GAB.
     *
     * @return array{pessoa: array<string, mixed>, vinculos: array<int, array<string, mixed>>}
     *
     * @throws RuntimeException quando o Hub não responde ou recusa
     */
    public function acessoDaPessoa(string $hubUserId): array
    {
        $codigo = rawurlencode((string) config('services.hub.codigo', 'GAB'));
        $url = $this->baseUrl()."/api/v1/sistemas/{$codigo}/pessoas/".rawurlencode($hubUserId);

        try {
            $response = $this->request()->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException('Não foi possível conectar ao Govnex Hub. Confira HUB_BASE_URL.');
        }

        if (! $response->successful()) {
            throw new RuntimeException(match (true) {
                $response->status() === 401 || $response->status() === 403 => 'O Govnex Hub recusou o acesso à API. Confira HUB_API_SECRET.',
                $response->status() === 404 => 'O Govnex Hub não encontrou a pessoa ou o sistema informado.',
                default => 'O Govnex Hub recusou a consulta de acesso (HTTP '.$response->status().').',
            });
        }

        $dados = $response->json('data');

        if (! is_array($dados)) {
            throw new RuntimeException('O Govnex Hub respondeu num formato inesperado ao consultar o acesso da pessoa.');
        }

        $vinculos = $dados['vinculos'] ?? [];

        return [
            'pessoa' => [
                'id' => isset($dados['id']) ? (string) $dados['id'] : $hubUserId,
                'nome' => $dados['nome'] ?? null,
                'email' => $dados['email'] ?? null,
                'ativo' => $dados['ativo'] ?? true,
            ],
            // A API só devolve vínculos vigentes (`Vinculo::vigentes()`), então
            // o que não veio na lista é exatamente o que deve ser desativado
            // aqui — é o que `HubVinculoSyncService::aplicar()` faz com o que
            // sobrou.
            'vinculos' => is_array($vinculos)
                ? array_values(array_filter($vinculos, 'is_array'))
                : [],
        ];
    }

    /**
     * Contas ativas do Hub.
     *
     * @return array<int, array<string, mixed>>
     */
    public function contas(): array
    {
        return $this->lista('/api/v1/contas');
    }

    /**
     * Entidades de uma conta (inclui `slug`; `status` e `atualizado_em` a
     * partir do contrato de estrutura). O Hub esconde as suspensas por
     * padrão — a reconciliação pede todas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function entidadesDaConta(string|int $contaId, bool $somenteAtivas = true): array
    {
        return $this->lista('/api/v1/contas/'.rawurlencode((string) $contaId).'/entidades'.$this->filtroAtivas($somenteAtivas));
    }

    /**
     * Unidades de uma entidade, já aninhadas em `unidades` (inclui `slug`,
     * `ativa` e, a partir do contrato de estrutura, `atualizado_em`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function unidadesDaEntidade(string|int $entidadeId, bool $somenteAtivas = true): array
    {
        return $this->lista('/api/v1/entidades/'.rawurlencode((string) $entidadeId).'/unidades'.$this->filtroAtivas($somenteAtivas));
    }

    /**
     * Uma entidade, para criar sob demanda o que ainda não foi espelhado.
     * Além dos campos da listagem traz `habilitado` (o GAB está entre os
     * sistemas habilitados da entidade), `timezone` e município/UF já
     * resolvidos (da entidade, senão da conta). `null` quando o Hub não a
     * conhece (inexistente ou removida).
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException
     */
    public function entidade(string|int $entidadeId): ?array
    {
        return $this->item('/api/v1/entidades/'.rawurlencode((string) $entidadeId));
    }

    /**
     * Uma unidade (com `unidade_pai_id`, `tipo` e o bloco da entidade).
     * `null` quando o Hub não a conhece.
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException
     */
    public function unidade(string|int $unidadeId): ?array
    {
        return $this->item('/api/v1/unidades/'.rawurlencode((string) $unidadeId));
    }

    private function filtroAtivas(bool $somenteAtivas): string
    {
        return $somenteAtivas ? '' : '?somente_ativas=false';
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException
     */
    private function lista(string $caminho): array
    {
        try {
            $response = $this->request()->get($this->baseUrl().$caminho);
        } catch (ConnectionException) {
            throw new RuntimeException('Não foi possível conectar ao Govnex Hub. Confira HUB_BASE_URL.');
        }

        if (! $response->successful()) {
            throw new RuntimeException(match (true) {
                $response->status() === 401 || $response->status() === 403 => 'O Govnex Hub recusou o acesso à API. Confira HUB_API_SECRET.',
                default => 'O Govnex Hub recusou a consulta de estrutura (HTTP '.$response->status().').',
            });
        }

        $dados = $response->json('data');

        if (! is_array($dados)) {
            throw new RuntimeException('O Govnex Hub respondeu num formato inesperado ao consultar a estrutura.');
        }

        return array_values(array_filter($dados, 'is_array'));
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException
     */
    private function item(string $caminho): ?array
    {
        try {
            $response = $this->request()->get($this->baseUrl().$caminho);
        } catch (ConnectionException) {
            throw new RuntimeException('Não foi possível conectar ao Govnex Hub. Confira HUB_BASE_URL.');
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException(match (true) {
                $response->status() === 401 || $response->status() === 403 => 'O Govnex Hub recusou o acesso à API. Confira HUB_API_SECRET.',
                default => 'O Govnex Hub recusou a consulta de estrutura (HTTP '.$response->status().').',
            });
        }

        $dados = $response->json('data');

        if (! is_array($dados)) {
            throw new RuntimeException('O Govnex Hub respondeu num formato inesperado ao consultar a estrutura.');
        }

        return $dados;
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('services.hub.base_url'), '/');

        if ($base === '') {
            throw new RuntimeException('HUB_BASE_URL não está configurada.');
        }

        return $base;
    }

    private function request(): PendingRequest
    {
        $segredo = (string) config('services.hub.api_secret');

        if ($segredo === '') {
            throw new RuntimeException('HUB_API_SECRET não está configurado.');
        }

        return Http::acceptJson()
            ->withToken($segredo)
            ->timeout((int) config('services.hub.timeout', 15))
            // Só vale repetir o que pode passar sozinho: queda de conexão, 5xx
            // e limite por minuto. Um 401 repetido daria a mesma resposta — e
            // indica segredo errado, não instabilidade.
            ->retry(
                3,
                fn (int $tentativa): int => 500 * $tentativa,
                fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException
                        && ($e->response->status() === 429 || $e->response->serverError())),
                throw: false,
            );
    }
}

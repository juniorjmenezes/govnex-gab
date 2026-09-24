<?php

namespace App\Services\Hub;

use App\Enums\EntidadeStatus;
use App\Enums\EntidadeType;
use App\Enums\GabineteStatus;
use App\Enums\GabineteType;
use App\Models\Entidade;
use App\Models\Gabinete;
use App\Services\Entidades\EstruturaProvisioningService;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Aplica no GAB o nome e a situação que o Hub declara para a estrutura já
 * espelhada (entidades e unidades ligadas por `hub_entidade_id` /
 * `hub_unidade_id`).
 *
 * O Hub é a fonte da verdade desses dois campos — e só deles. Slug, tipo,
 * cores, módulos e licença continuam do GAB: o slug do Hub muda a cada
 * renomeação, e aqui ele é URL. Depois de ligado, o casamento é só pelo id.
 *
 * Mesmo contrato do resto da integração: retrato, não diferença. A ordem vem
 * do `atualizado_em` do registro no Hub, guardado em `hub_sincronizado_em`;
 * retrato mais antigo que o último aplicado é descartado. Remoção no Hub
 * suspende aqui — nunca apaga, para ser reversível e não perder os dados
 * operacionais do gabinete.
 *
 * Criação (`criarEntidade`/`criarUnidade`): o Hub é o único ponto de criação
 * de estrutura, e o que ele cria para o GAB nasce aqui pelo mesmo caminho da
 * antiga criação manual (`EstruturaProvisioningService`) — licença, módulos,
 * localização herdada —, sem usuário: o acesso vem dos vínculos. Só entra o
 * que tem equivalente no GAB (`HubTipoMapper`); o resto é ignorado com log e
 * responde 200. A fila não garante ordem, então unidade (ou vínculo) que
 * chega antes da entidade a resolve sob demanda pela API do Hub. Criar é
 * idempotente: item já ligado cai no caminho de atualização, e o `unique` de
 * `hub_entidade_id`/`hub_unidade_id` segura a criação simultânea.
 *
 * Item não ligado em `*.alterada`/`*.removida` não é erro: `Log::info` e 200
 * (docs/INTEGRACAO_GOVNEX_HUB.md).
 */
class HubEstruturaSyncService
{
    public function __construct(
        private readonly EstruturaProvisioningService $provisionamento,
        private readonly HubTipoMapper $tipos,
        private readonly GovnexHubApiClient $hub,
    ) {}

    /**
     * `entidade.criada` (ou item da API ao resolver sob demanda).
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function criarEntidade(array $dados): array
    {
        $hubId = $this->idDoHub($dados, 'entidade');
        $existente = Entidade::withTrashed()->where('hub_entidade_id', $hubId)->first();

        if ($existente !== null) {
            return $existente->trashed()
                ? $this->ignorar('entidade', $hubId, 'excluida_no_gab')
                : $this->aplicarEntidade($dados);
        }

        $motivo = $this->motivoParaNaoCriarEntidade($dados);

        if ($motivo !== null) {
            return $this->ignorar('entidade', $hubId, $motivo, ['tipo' => $this->tipoDaEntidadeNoHub($dados)]);
        }

        // Entidade local ainda não ligada com o mesmo slug do Hub é, pela regra
        // do espelhamento, a mesma entidade (veio do GAB pelo importador).
        // Criar outra seria duplicar; ligar é tarefa do `hub:espelhar-estrutura`.
        if ($this->casamentoPendente(Entidade::query(), 'hub_entidade_id', $dados)) {
            return $this->ignorar('entidade', $hubId, 'casamento_pendente', ['slug' => $dados['slug'] ?? null]);
        }

        /** @var EntidadeType $tipo */
        $tipo = $this->tipos->entidadeType($this->tipoDaEntidadeNoHub($dados));
        $ativa = ($dados['status'] ?? 'ativa') === 'ativa';

        try {
            $entidade = $this->provisionamento->criarEntidade($tipo, [
                'nome' => (string) $this->nome($dados, 180),
                'municipio' => $this->municipio($dados),
                'estado' => $this->estado($dados),
                'timezone' => $this->timezone($dados['timezone'] ?? null),
                'status' => $ativa ? EntidadeStatus::Active : EntidadeStatus::Suspended,
                'suspensa_em' => $ativa ? null : now(),
                'hub_entidade_id' => $hubId,
                'hub_sincronizado_em' => $this->carimbo($dados['atualizado_em'] ?? null),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Outra entrega (o `entidade.criada` e um vínculo sob demanda, por
            // exemplo) criou a mesma entidade no meio do caminho.
            if (Entidade::query()->where('hub_entidade_id', $hubId)->exists()) {
                return $this->aplicarEntidade($dados);
            }

            throw $e;
        }

        Log::info('Entidade criada no GAB a partir do Govnex Hub.', [
            'entidade_id' => $entidade->id,
            'hub_entidade_id' => $hubId,
            'tipo' => $tipo->value,
        ]);

        return ['acao' => 'entidade_criada', 'entidade_id' => $entidade->id];
    }

    /**
     * `unidade.criada` (ou item da API ao resolver sob demanda). Só unidade
     * raiz: o GAB é plano.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     *
     * @throws HubIndisponivelException quando a entidade precisa ser resolvida e o Hub não responde
     */
    public function criarUnidade(array $dados): array
    {
        $hubId = $this->idDoHub($dados, 'unidade');
        $existente = Gabinete::withoutGlobalScopes()->where('hub_unidade_id', $hubId)->first();

        if ($existente !== null) {
            return $existente->trashed()
                ? $this->ignorar('unidade', $hubId, 'excluida_no_gab')
                : $this->aplicarUnidade($dados);
        }

        // Aninhada é descartada antes de qualquer consulta ao Hub.
        if ($this->texto($dados['unidade_pai_id'] ?? null) !== null) {
            return $this->ignorar('unidade', $hubId, 'unidade_aninhada');
        }

        $hubEntidadeId = $this->texto($dados['entidade_id'] ?? null);
        $entidade = $hubEntidadeId === null ? null : $this->resolverEntidade($hubEntidadeId);

        if ($entidade === null) {
            return $this->ignorar('unidade', $hubId, 'entidade_nao_espelhada', ['hub_entidade_id' => $hubEntidadeId]);
        }

        $motivo = $this->motivoParaNaoCriarUnidade($entidade->tipo, $dados);

        if ($motivo !== null) {
            return $this->ignorar('unidade', $hubId, $motivo, [
                'tipo' => $dados['tipo'] ?? null,
                'entidade_tipo' => $entidade->tipo->value,
            ]);
        }

        if ($this->casamentoPendente(Gabinete::withoutGlobalScopes()->where('entidade_id', $entidade->id), 'hub_unidade_id', $dados)) {
            return $this->ignorar('unidade', $hubId, 'casamento_pendente', ['slug' => $dados['slug'] ?? null]);
        }

        /** @var GabineteType $tipo */
        $tipo = $this->tipos->gabineteType($entidade->tipo, $dados['tipo'] ?? null);
        $ativa = $this->booleano($dados['ativa'] ?? true);

        try {
            $gabinete = $this->provisionamento->criarGabinete($entidade, $tipo, [
                'nome' => (string) $this->nome($dados, 255),
                'status' => $ativa ? GabineteStatus::Active : GabineteStatus::Suspended,
                'suspended_at' => $ativa ? null : now(),
                'hub_unidade_id' => $hubId,
                'hub_sincronizado_em' => $this->carimbo($dados['atualizado_em'] ?? null),
            ], null, ['origem' => 'GOVNEX_HUB', 'hub_unidade_id' => $hubId]);
        } catch (InvalidArgumentException $e) {
            // Gabinete independente que já tem o seu (inclusive por criação
            // simultânea): o GAB não aceita um segundo.
            return $this->ignorar('unidade', $hubId, 'entidade_nao_aceita', ['erro' => $e->getMessage()]);
        } catch (UniqueConstraintViolationException $e) {
            if (Gabinete::withoutGlobalScopes()->where('hub_unidade_id', $hubId)->exists()) {
                return $this->aplicarUnidade($dados);
            }

            throw $e;
        }

        Log::info('Gabinete criado no GAB a partir do Govnex Hub.', [
            'gabinete_id' => $gabinete->id,
            'entidade_id' => $entidade->id,
            'hub_unidade_id' => $hubId,
            'tipo_gabinete' => $tipo->value,
        ]);

        return ['acao' => 'unidade_criada', 'gabinete_id' => $gabinete->id];
    }

    /**
     * Por que a entidade do Hub não nasce no GAB (`null` quando nasce). Usado
     * pela criação e pelo `hub:espelhar-estrutura --criar --dry-run`.
     *
     * @param  array<string, mixed>  $dados
     */
    public function motivoParaNaoCriarEntidade(array $dados): ?string
    {
        if ($this->tipos->entidadeType($this->tipoDaEntidadeNoHub($dados)) === null) {
            return 'tipo_sem_equivalente';
        }

        // `habilitado` só vem da API; o webhook já chega filtrado pela
        // habilitação no Hub.
        if (array_key_exists('habilitado', $dados) && $dados['habilitado'] === false) {
            return 'gab_nao_habilitado';
        }

        if ($this->nome($dados, 180) === null
            || $this->municipio($dados) === ''
            || preg_match('/^[A-Z]{2}$/', $this->estado($dados)) !== 1) {
            return 'dados_incompletos';
        }

        return null;
    }

    /**
     * Por que a unidade do Hub não vira gabinete numa entidade do tipo
     * informado (`null` quando vira).
     *
     * @param  array<string, mixed>  $dados
     */
    public function motivoParaNaoCriarUnidade(EntidadeType $tipoDaEntidade, array $dados): ?string
    {
        if ($this->texto($dados['unidade_pai_id'] ?? null) !== null) {
            return 'unidade_aninhada';
        }

        if ($this->tipos->gabineteType($tipoDaEntidade, $dados['tipo'] ?? null) === null) {
            return 'tipo_sem_equivalente';
        }

        return $this->nome($dados, 255) === null ? 'dados_incompletos' : null;
    }

    /**
     * Entidade espelhada; se ainda não existir aqui, pergunta ao Hub e cria
     * (respeitando a habilitação do GAB na entidade). `null` quando o Hub não
     * a conhece, o GAB não está habilitado nela ou o tipo não tem equivalente.
     *
     * @throws HubIndisponivelException
     */
    public function resolverEntidade(string $hubEntidadeId): ?Entidade
    {
        $local = Entidade::query()->where('hub_entidade_id', $hubEntidadeId)->first();

        if ($local !== null) {
            return $local;
        }

        try {
            $dados = $this->hub->entidade($hubEntidadeId);
        } catch (RuntimeException $e) {
            throw new HubIndisponivelException($e->getMessage(), 0, $e);
        }

        if ($dados === null) {
            Log::info('Entidade desconhecida pelo Govnex Hub; nada a criar.', ['hub_entidade_id' => $hubEntidadeId]);

            return null;
        }

        $this->criarEntidade(['id' => $hubEntidadeId] + $dados);

        return Entidade::query()->where('hub_entidade_id', $hubEntidadeId)->first();
    }

    /**
     * Gabinete espelhado da unidade; se ainda não existir, pergunta ao Hub e
     * cria (resolvendo a entidade antes, se for o caso).
     *
     * @throws HubIndisponivelException
     */
    public function resolverUnidade(string $hubEntidadeId, string $hubUnidadeId): ?Gabinete
    {
        $local = Gabinete::withoutGlobalScopes()->where('hub_unidade_id', $hubUnidadeId)->first();

        if ($local !== null) {
            return $local;
        }

        try {
            $dados = $this->hub->unidade($hubUnidadeId);
        } catch (RuntimeException $e) {
            throw new HubIndisponivelException($e->getMessage(), 0, $e);
        }

        if ($dados === null || $this->texto($dados['entidade_id'] ?? null) !== $hubEntidadeId) {
            Log::info('Unidade desconhecida pelo Govnex Hub ou de outra entidade; nada a criar.', [
                'hub_entidade_id' => $hubEntidadeId,
                'hub_unidade_id' => $hubUnidadeId,
            ]);

            return null;
        }

        $this->criarUnidade(['id' => $hubUnidadeId] + $dados);

        return Gabinete::withoutGlobalScopes()->where('hub_unidade_id', $hubUnidadeId)->first();
    }

    /**
     * @param  array<string, mixed>  $dados  bloco `dados.entidade` do webhook ou item da API
     * @return array<string, mixed>
     */
    public function aplicarEntidade(array $dados, bool $removida = false): array
    {
        $hubId = $this->idDoHub($dados, 'entidade');
        $entidade = Entidade::query()->where('hub_entidade_id', $hubId)->first();

        if ($entidade === null) {
            Log::info('Entidade do Hub sem correspondência ligada no GAB; aviso ignorado.', ['hub_entidade_id' => $hubId]);

            return ['acao' => 'entidade_ignorada', 'hub_entidade_id' => $hubId];
        }

        $mudancas = $this->mudancasDaEntidade($entidade, $dados, $removida);

        if ($mudancas === null) {
            return ['acao' => 'evento_antigo_descartado', 'entidade_id' => $entidade->id];
        }

        $entidade->forceFill($mudancas)->save();

        return ['acao' => 'entidade_sincronizada', 'entidade_id' => $entidade->id];
    }

    /**
     * @param  array<string, mixed>  $dados  bloco `dados.unidade` do webhook ou item da API
     * @return array<string, mixed>
     */
    public function aplicarUnidade(array $dados, bool $removida = false): array
    {
        $hubId = $this->idDoHub($dados, 'unidade');
        $gabinete = Gabinete::withoutGlobalScopes()->where('hub_unidade_id', $hubId)->first();

        if ($gabinete === null) {
            Log::info('Unidade do Hub sem gabinete ligado no GAB; aviso ignorado.', ['hub_unidade_id' => $hubId]);

            return ['acao' => 'unidade_ignorada', 'hub_unidade_id' => $hubId];
        }

        $mudancas = $this->mudancasDaUnidade($gabinete, $dados, $removida);

        if ($mudancas === null) {
            return ['acao' => 'evento_antigo_descartado', 'gabinete_id' => $gabinete->id];
        }

        $gabinete->forceFill($mudancas)->save();

        return ['acao' => 'unidade_sincronizada', 'gabinete_id' => $gabinete->id];
    }

    /**
     * O que o retrato muda na entidade: só `nome` e situação, mais o carimbo.
     * `null` quando o retrato é mais antigo que o último aplicado.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>|null
     */
    public function mudancasDaEntidade(Entidade $entidade, array $dados, bool $removida = false): ?array
    {
        $carimbo = $this->carimbo($dados['atualizado_em'] ?? null);

        if ($this->maisAntigo($carimbo, $entidade->hub_sincronizado_em)) {
            return null;
        }

        $mudancas = [];
        $nome = $this->nome($dados, 180);

        if ($nome !== null && $nome !== $entidade->nome) {
            $mudancas['nome'] = $nome;
        }

        // Sem `status` no retrato (Hub anterior a este contrato), a situação
        // não é afirmada — fica como está.
        $ativa = $removida ? false : (array_key_exists('status', $dados) ? $dados['status'] === 'ativa' : null);

        if ($ativa !== null) {
            $status = $ativa ? EntidadeStatus::Active : EntidadeStatus::Suspended;

            if ($entidade->status !== $status) {
                $mudancas['status'] = $status;
                $mudancas['suspensa_em'] = $ativa ? null : now();
            }
        }

        return $mudancas + $this->novoCarimbo($carimbo, $entidade->hub_sincronizado_em);
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>|null
     */
    public function mudancasDaUnidade(Gabinete $gabinete, array $dados, bool $removida = false): ?array
    {
        $carimbo = $this->carimbo($dados['atualizado_em'] ?? null);

        if ($this->maisAntigo($carimbo, $gabinete->hub_sincronizado_em)) {
            return null;
        }

        $mudancas = [];
        $nome = $this->nome($dados, 255);

        if ($nome !== null && $nome !== $gabinete->nome) {
            $mudancas['nome'] = $nome;
        }

        $ativa = $removida ? false : (array_key_exists('ativa', $dados) ? (bool) $dados['ativa'] : null);

        if ($ativa !== null) {
            $status = $ativa ? GabineteStatus::Active : GabineteStatus::Suspended;

            if ($gabinete->status !== $status) {
                $mudancas['status'] = $status;
                $mudancas['suspended_at'] = $ativa ? null : now();
            }
        }

        return $mudancas + $this->novoCarimbo($carimbo, $gabinete->hub_sincronizado_em);
    }

    /** @param  array<string, mixed>  $dados */
    private function idDoHub(array $dados, string $recurso): string
    {
        $id = isset($dados['id']) ? trim((string) $dados['id']) : '';

        if ($id === '') {
            throw new RuntimeException("Evento de {$recurso} do Hub sem identificador.");
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $contexto
     * @return array<string, mixed>
     */
    private function ignorar(string $recurso, string $hubId, string $motivo, array $contexto = []): array
    {
        Log::info("Criação de {$recurso} do Govnex Hub ignorada no GAB.", [
            "hub_{$recurso}_id" => $hubId,
            'motivo' => $motivo,
            ...$contexto,
        ]);

        return ['acao' => "{$recurso}_ignorada", "hub_{$recurso}_id" => $hubId, 'motivo' => $motivo];
    }

    /**
     * O webhook manda `tipo`; a API de leitura o traz em `classificacoes.tipo`.
     *
     * @param  array<string, mixed>  $dados
     */
    public function tipoDaEntidadeNoHub(array $dados): mixed
    {
        return $dados['tipo'] ?? (is_array($dados['classificacoes'] ?? null) ? ($dados['classificacoes']['tipo'] ?? null) : null);
    }

    /**
     * Há item local sem ligação com o Hub e com o slug que o Hub informou?
     *
     * @param  Builder<Entidade>|Builder<Gabinete>  $query
     * @param  array<string, mixed>  $dados
     */
    private function casamentoPendente(Builder $query, string $colunaDoHub, array $dados): bool
    {
        $slug = $this->texto($dados['slug'] ?? null);

        return $slug !== null && $query->where('slug', $slug)->whereNull($colunaDoHub)->exists();
    }

    /** @param  array<string, mixed>  $dados */
    private function municipio(array $dados): string
    {
        return Str::substr(Str::squish((string) ($dados['municipio'] ?? '')), 0, 120);
    }

    /** @param  array<string, mixed>  $dados */
    private function estado(array $dados): string
    {
        return strtoupper(trim((string) ($dados['estado'] ?? '')));
    }

    private function timezone(mixed $valor): string
    {
        return is_string($valor) && in_array($valor, timezone_identifiers_list(), true)
            ? $valor
            : 'America/Sao_Paulo';
    }

    private function texto(mixed $valor): ?string
    {
        $texto = $valor === null ? '' : trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function booleano(mixed $valor): bool
    {
        return filter_var($valor, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /** @param  array<string, mixed>  $dados */
    private function nome(array $dados, int $limite): ?string
    {
        $nome = Str::squish((string) ($dados['nome'] ?? ''));

        return $nome === '' ? null : Str::substr($nome, 0, $limite);
    }

    private function carimbo(mixed $valor): ?CarbonInterface
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        try {
            // O Hub manda ISO 8601 com deslocamento; o Eloquent grava a hora
            // sem converter fuso, então normaliza para o fuso da aplicação.
            return Carbon::parse($valor)->setTimezone((string) config('app.timezone'));
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * Estritamente mais antigo: `updated_at` tem resolução de segundo, e duas
     * mudanças no mesmo segundo precisam ser aplicadas as duas (o retrato é
     * idempotente, reaplicar o mesmo estado não faz mal).
     */
    private function maisAntigo(?CarbonInterface $carimbo, ?CarbonInterface $aplicado): bool
    {
        return $carimbo !== null && $aplicado !== null && $carimbo->lt($aplicado);
    }

    /** @return array<string, CarbonInterface> */
    private function novoCarimbo(?CarbonInterface $carimbo, ?CarbonInterface $aplicado): array
    {
        if ($carimbo === null || ($aplicado !== null && $carimbo->lte($aplicado))) {
            return [];
        }

        return ['hub_sincronizado_em' => $carimbo];
    }
}

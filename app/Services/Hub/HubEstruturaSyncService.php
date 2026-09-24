<?php

namespace App\Services\Hub;

use App\Enums\EntidadeStatus;
use App\Enums\GabineteStatus;
use App\Models\Entidade;
use App\Models\Gabinete;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
 * Item que não está ligado não é erro: a estrutura nova do Hub só nasce no
 * GAB na fase seguinte (docs/INTEGRACAO_GOVNEX_HUB.md).
 */
class HubEstruturaSyncService
{
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

<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Visibilidade em três níveis por duas colunas nullable, `gabinete_id` e
 * `entidade_id`:
 *
 * - `gabinete_id` preenchido: privado daquele gabinete;
 * - `gabinete_id` nulo e `entidade_id` preenchido: compartilhado com todos os
 *   gabinetes da entidade (ex.: o que a Secretaria legislativa publica para a
 *   Câmara);
 * - ambos nulos: biblioteca da plataforma, visível a todos os gabinetes.
 *
 * Quem usa este trait fica deliberadamente fora de `BelongsToOffice` /
 * `TenantModel`: o escopo global de tenant exige `gabinete_id` igual ao do
 * gabinete corrente, e aqui a visibilidade depende de duas chaves, não de
 * correspondência exata — a mesma decisão já documentada em
 * `KnowledgeDocument`. Sem escopo global, toda consulta de leitura precisa
 * passar por `visibleTo()`.
 *
 * O trait não resolve contexto: quem consulta passa `$gabineteId` e
 * `$entidadeId` do gabinete corrente. O `$entidadeId` precisa vir do próprio
 * gabinete (nunca da requisição), senão um gabinete enxergaria o compartilhado
 * de outra entidade.
 *
 * @property int|null $gabinete_id
 * @property int|null $entidade_id
 */
trait HasTieredVisibility
{
    public function isPlatform(): bool
    {
        return $this->gabinete_id === null && $this->entidade_id === null;
    }

    public function isEntidadeWide(): bool
    {
        return $this->gabinete_id === null && $this->entidade_id !== null;
    }

    public function isGabinete(): bool
    {
        return $this->gabinete_id !== null;
    }

    /**
     * O que o gabinete enxerga: os registros dele, os compartilhados com a
     * entidade dele e os da plataforma. Sem `$entidadeId`, só dois níveis
     * (gabinete e plataforma) — o comportamento anterior; o compartilhado
     * com a entidade fica de fora.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeVisibleTo(Builder $query, int $gabineteId, ?int $entidadeId = null): Builder
    {
        $gabineteColumn = $query->qualifyColumn('gabinete_id');
        $entidadeColumn = $query->qualifyColumn('entidade_id');

        return $query->where(function (Builder $visible) use ($gabineteColumn, $entidadeColumn, $gabineteId, $entidadeId): void {
            $visible->where($gabineteColumn, $gabineteId)
                ->orWhere(fn (Builder $shared) => $shared
                    ->whereNull($gabineteColumn)
                    ->where(fn (Builder $tier) => $tier
                        ->whereNull($entidadeColumn)
                        ->when($entidadeId !== null, fn (Builder $tier) => $tier
                            ->orWhere($entidadeColumn, $entidadeId))));
        });
    }

    /**
     * Só os registros privados do gabinete.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeOwnedByGabinete(Builder $query, int $gabineteId): Builder
    {
        return $query->where($query->qualifyColumn('gabinete_id'), $gabineteId);
    }

    /**
     * Só os registros compartilhados com a entidade inteira.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeSharedWithEntidade(Builder $query, int $entidadeId): Builder
    {
        return $query->whereNull($query->qualifyColumn('gabinete_id'))
            ->where($query->qualifyColumn('entidade_id'), $entidadeId);
    }
}

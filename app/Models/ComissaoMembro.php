<?php

namespace App\Models;

use App\Enums\CommitteeRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gabinete de vereador numa comissão. `gabinete_id` é referência ao gabinete
 * de origem, não posse: o registro é da comissão (e, por ela, da entidade).
 * Por isso as relações leem `Gabinete`/`User` sem o escopo global — no
 * contexto de outro gabinete da Câmara, o escopo só enxergaria o próprio.
 *
 * Criado e alterado só por `CommitteeMembershipService`, que garante as
 * regras de domínio (mesma entidade, gabinete de vereador, um presidente).
 *
 * @property int $id
 * @property int $comissao_id
 * @property int $gabinete_id
 * @property int|null $usuario_id
 * @property CommitteeRole $cargo
 * @property CarbonImmutable $ingressou_em
 * @property CarbonImmutable|null $saiu_em
 * @property-read ComissaoLegislativa $comissao
 * @property-read Gabinete $gabinete
 * @property-read User|null $usuario
 */
class ComissaoMembro extends Model
{
    protected $table = 'comissao_membros';

    /** @var list<string> */
    protected $guarded = ['id', 'comissao_id', 'gabinete_id'];

    /**
     * Participação em curso: sem `saiu_em`. Extinguir a comissão preenche a
     * saída de todos (`CommitteeMembershipService::closeAll()`).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('saiu_em'));
    }

    /** @return BelongsTo<ComissaoLegislativa, $this> */
    public function comissao(): BelongsTo
    {
        return $this->belongsTo(ComissaoLegislativa::class, 'comissao_id');
    }

    /** @return BelongsTo<Gabinete, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Gabinete::class, 'gabinete_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id')->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'cargo' => CommitteeRole::class,
            'ingressou_em' => 'date',
            'saiu_em' => 'date',
        ];
    }
}

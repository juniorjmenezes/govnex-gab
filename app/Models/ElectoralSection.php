<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $eleicao_id
 * @property int $municipio_eleitoral_id
 * @property int $local_votacao_eleitoral_id
 * @property string $nr_zona
 * @property string $nr_secao
 * @property int|null $eleitores_secao
 * @property-read Election $eleicao
 * @property-read ElectoralMunicipality $municipioEleitoral
 * @property-read ElectoralPollingLocation $localVotacao
 */
class ElectoralSection extends Model
{
    protected $table = 'secoes_eleitorais';

    protected $guarded = [];

    /** @return BelongsTo<Election, $this> */
    public function eleicao(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'eleicao_id');
    }

    /** @return BelongsTo<ElectoralMunicipality, $this> */
    public function municipioEleitoral(): BelongsTo
    {
        return $this->belongsTo(ElectoralMunicipality::class, 'municipio_eleitoral_id');
    }

    /** @return BelongsTo<ElectoralPollingLocation, $this> */
    public function localVotacao(): BelongsTo
    {
        return $this->belongsTo(ElectoralPollingLocation::class, 'local_votacao_eleitoral_id');
    }

    /** @return HasMany<CandidateSectionVote, $this> */
    public function votos(): HasMany
    {
        return $this->hasMany(CandidateSectionVote::class, 'secao_eleitoral_id');
    }
}

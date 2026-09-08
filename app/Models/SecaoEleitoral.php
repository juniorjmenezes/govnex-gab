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
 * @property-read Eleicao $eleicao
 * @property-read MunicipioEleitoral $municipioEleitoral
 * @property-read LocalVotacaoEleitoral $localVotacao
 */
class SecaoEleitoral extends Model
{
    protected $table = 'secoes_eleitorais';

    protected $guarded = [];

    /** @return BelongsTo<Eleicao, $this> */
    public function eleicao(): BelongsTo
    {
        return $this->belongsTo(Eleicao::class);
    }

    /** @return BelongsTo<MunicipioEleitoral, $this> */
    public function municipioEleitoral(): BelongsTo
    {
        return $this->belongsTo(MunicipioEleitoral::class);
    }

    /** @return BelongsTo<LocalVotacaoEleitoral, $this> */
    public function localVotacao(): BelongsTo
    {
        return $this->belongsTo(LocalVotacaoEleitoral::class, 'local_votacao_eleitoral_id');
    }

    /** @return HasMany<VotoSecaoCandidato, $this> */
    public function votos(): HasMany
    {
        return $this->hasMany(VotoSecaoCandidato::class, 'secao_eleitoral_id');
    }
}

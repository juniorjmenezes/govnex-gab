<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $municipio_eleitoral_id
 * @property int|null $eleicao_id
 * @property string $codigo_eleicao_tse
 * @property int $ano
 * @property int $turno
 * @property Carbon $data_eleicao
 * @property int $eleitores_aptos
 * @property int $comparecimento
 * @property int $abstencoes
 * @property string $fonte_url
 * @property Carbon|null $fonte_gerada_em
 * @property-read Election|null $eleicao
 */
class MunicipalElectionTurnout extends Model
{
    protected $table = 'comparecimentos_eleitorais_municipio';

    protected $guarded = [];

    /** @return BelongsTo<ElectoralMunicipality, $this> */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(ElectoralMunicipality::class, 'municipio_eleitoral_id');
    }

    /** @return BelongsTo<Election, $this> */
    public function eleicao(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'eleicao_id');
    }

    protected function casts(): array
    {
        return [
            'data_eleicao' => 'date',
            'fonte_gerada_em' => 'datetime',
        ];
    }
}

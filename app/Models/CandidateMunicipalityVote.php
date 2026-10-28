<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $candidato_politico_id
 * @property int $municipio_eleitoral_id
 * @property int|null $eleicao_id
 * @property string $codigo_eleicao_tse
 * @property int $ano
 * @property int $turno
 * @property Carbon $data_eleicao
 * @property int $votos_nominais
 * @property int $votos_nominais_validos
 * @property string|null $situacao_totalizacao
 * @property bool $eleito
 * @property string $fonte_url
 * @property Carbon|null $fonte_gerada_em
 * @property-read Candidate $candidato
 * @property-read Election|null $eleicao
 */
class CandidateMunicipalityVote extends Model
{
    protected $table = 'votacoes_candidatos_municipio';

    protected $guarded = [];

    /** @return BelongsTo<Candidate, $this> */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidato_politico_id');
    }

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
            'eleito' => 'boolean',
            'fonte_gerada_em' => 'datetime',
        ];
    }
}

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
 * @property-read CandidatoPolitico $candidato
 * @property-read Eleicao|null $eleicao
 */
class VotacaoCandidatoMunicipio extends Model
{
    protected $table = 'votacoes_candidatos_municipio';

    protected $guarded = [];

    /** @return BelongsTo<CandidatoPolitico, $this> */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(CandidatoPolitico::class, 'candidato_politico_id');
    }

    /** @return BelongsTo<MunicipioEleitoral, $this> */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(MunicipioEleitoral::class, 'municipio_eleitoral_id');
    }

    /** @return BelongsTo<Eleicao, $this> */
    public function eleicao(): BelongsTo
    {
        return $this->belongsTo(Eleicao::class);
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

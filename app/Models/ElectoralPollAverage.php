<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $eleicao_id
 * @property string $external_id
 * @property string $external_election_id
 * @property string $external_candidate_id
 * @property int|null $candidato_politico_id
 * @property int $ano
 * @property string $uf
 * @property string|null $municipio
 * @property string $cargo
 * @property string $candidato_nome
 * @property string|null $partido_sigla
 * @property float $media_ponderada
 * @property float|null $intervalo_confianca_min
 * @property float|null $intervalo_confianca_max
 * @property int $pesquisas_incluidas
 * @property int $amostra_total
 * @property Carbon|null $calculada_em
 * @property string $fonte_url
 */
class ElectoralPollAverage extends Model
{
    protected $table = 'medias_pesquisas_eleitorais';

    protected $guarded = [];

    /** @return BelongsTo<Election, $this> */
    public function eleicao(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'eleicao_id');
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidato_politico_id');
    }

    protected function casts(): array
    {
        return [
            'media_ponderada' => 'float',
            'intervalo_confianca_min' => 'float',
            'intervalo_confianca_max' => 'float',
            'calculada_em' => 'datetime',
        ];
    }
}

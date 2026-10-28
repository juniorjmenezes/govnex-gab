<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $secao_eleitoral_id
 * @property int $candidato_politico_id
 * @property int $votos
 * @property string $fonte_url
 * @property Carbon|null $fonte_gerada_em
 * @property-read ElectoralSection $secao
 * @property-read Candidate $candidato
 */
class CandidateSectionVote extends Model
{
    protected $table = 'votos_secao_candidato';

    protected $guarded = [];

    /** @return BelongsTo<ElectoralSection, $this> */
    public function secao(): BelongsTo
    {
        return $this->belongsTo(ElectoralSection::class, 'secao_eleitoral_id');
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidato_politico_id');
    }

    protected function casts(): array
    {
        return [
            'fonte_gerada_em' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resultado final por turno de toda disputa do feed de apuração do TSE —
 * presidente, governador, senador e deputados —, gravado por
 * ElectionResultRecorder. Prefeito e vereador ficam em
 * CandidateMunicipalityVote.
 *
 * @property int $id
 * @property int $candidato_politico_id
 * @property int $eleicao_id
 * @property int $turno
 * @property int $votos
 * @property string $percentual_validos
 * @property string $situacao_totalizacao Vocabulário do TSE: "ELEITO", "NÃO ELEITO", "2º TURNO" e, nos
 *                                        proporcionais, "ELEITO POR QP", "ELEITO POR MÉDIA", "SUPLENTE".
 * @property bool $eleito
 * @property-read Candidate $candidato
 */
class CandidateElectionResult extends Model
{
    public const ELECTED = 'ELEITO';

    public const NOT_ELECTED = 'NÃO ELEITO';

    public const SECOND_ROUND = '2º TURNO';

    protected $table = 'resultados_candidatos_eleicao';

    protected $guarded = [];

    /** @return BelongsTo<Candidate, $this> */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidato_politico_id');
    }

    protected function casts(): array
    {
        return [
            'eleito' => 'boolean',
            'percentual_validos' => 'decimal:2',
        ];
    }
}

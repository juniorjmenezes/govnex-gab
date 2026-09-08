<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $pesquisa_eleitoral_id
 * @property string $external_candidate_id
 * @property int|null $candidato_politico_id
 * @property string $candidato_nome
 * @property string|null $partido_sigla
 * @property float $percentual
 * @property bool $nao_valido Opção agregada publicada pela pesquisa (ex.: "Não Válido", "Outros") — não é um candidato
 */
class ResultadoPesquisaEleitoral extends Model
{
    protected $table = 'resultados_pesquisas_eleitorais';

    protected $guarded = [];

    /** @return BelongsTo<PesquisaEleitoral, $this> */
    public function pesquisa(): BelongsTo
    {
        return $this->belongsTo(PesquisaEleitoral::class, 'pesquisa_eleitoral_id');
    }

    /** @return BelongsTo<CandidatoPolitico, $this> */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(CandidatoPolitico::class, 'candidato_politico_id');
    }

    protected function casts(): array
    {
        return [
            'percentual' => 'float',
            'nao_valido' => 'boolean',
        ];
    }
}

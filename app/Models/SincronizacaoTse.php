<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $gabinete_id
 * @property int|null $solicitado_por_id
 * @property string $dataset
 * @property int $ano
 * @property string|null $uf
 * @property string|null $arquivo_retido_path
 * @property string $situacao
 * @property int $registros_processados
 * @property string|null $progresso_etapa
 * @property int|null $progresso_percentual
 * @property Carbon $iniciada_em
 * @property Carbon|null $concluida_em
 */
class SincronizacaoTse extends Model
{
    protected $table = 'sincronizacoes_tse';

    protected $guarded = [];

    /** @return BelongsTo<Gabinete, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Gabinete::class);
    }

    /** @return BelongsTo<User, $this> */
    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por_id');
    }

    protected function casts(): array
    {
        return [
            'iniciada_em' => 'datetime',
            'concluida_em' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'dataset' => $this->dataset,
            'year' => $this->ano,
            'uf' => $this->uf,
            'status' => $this->situacao,
            'processed_records' => $this->registros_processados,
            'progress_stage' => $this->progresso_etapa,
            'progress_percent' => $this->progresso_percentual,
            'error' => $this->erro,
            'started_at' => $this->iniciada_em->toIso8601String(),
            'completed_at' => $this->concluida_em?->toIso8601String(),
        ];
    }
}

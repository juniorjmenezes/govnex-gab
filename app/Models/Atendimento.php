<?php

namespace App\Models;

use Database\Factories\AtendimentoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $gabinete_id
 * @property int $cidadao_id
 * @property int|null $atendente_id
 * @property int|null $demanda_id
 * @property int $criado_por_id
 * @property string $assunto
 * @property string $relato
 * @property string|null $providencias
 * @property Carbon $atendido_em
 * @property int|null $duracao_minutos
 * @property bool $requer_retorno
 * @property Carbon|null $retorno_previsto_em
 */
class Atendimento extends TenantModel
{
    /** @use HasFactory<AtendimentoFactory> */
    use HasFactory, SoftDeletes;

    /** @return BelongsTo<Cidadao, $this> */
    public function cidadao(): BelongsTo
    {
        return $this->belongsTo(Cidadao::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function atendente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendente_id')->withTrashed();
    }

    /** @return BelongsTo<Demanda, $this> */
    public function demanda(): BelongsTo
    {
        return $this->belongsTo(Demanda::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id')->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'atendido_em' => 'datetime',
            'duracao_minutos' => 'integer',
            'requer_retorno' => 'boolean',
            'retorno_previsto_em' => 'date',
        ];
    }
}

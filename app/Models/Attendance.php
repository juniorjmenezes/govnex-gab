<?php

namespace App\Models;

use Database\Factories\AttendanceFactory;
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
 * @property bool $visita_domiciliar
 * @property int|null $compromisso_id
 * @property string $assunto
 * @property string $relato
 * @property string|null $providencias
 * @property Carbon $atendido_em
 * @property int|null $duracao_minutos
 * @property bool $requer_retorno
 * @property Carbon|null $retorno_previsto_em
 */
class Attendance extends TenantModel
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory, SoftDeletes;

    // Tabela já publicada em português (ver AGENTS.md): só o nome da classe
    // segue a convenção em inglês, o schema não muda.
    protected $table = 'atendimentos';

    /** @return BelongsTo<Citizen, $this> */
    public function cidadao(): BelongsTo
    {
        return $this->belongsTo(Citizen::class, 'cidadao_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function atendente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendente_id')->withTrashed();
    }

    /** @return BelongsTo<Demand, $this> */
    public function demanda(): BelongsTo
    {
        return $this->belongsTo(Demand::class, 'demanda_id')->withTrashed();
    }

    /** @return BelongsTo<Appointment, $this> */
    public function compromisso(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'compromisso_id')->withTrashed();
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
            'visita_domiciliar' => 'boolean',
        ];
    }
}

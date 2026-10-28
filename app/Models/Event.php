<?php

namespace App\Models;

use App\Enums\EventDuration;
use App\Enums\EventStatus;
use App\Enums\EventType;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $gabinete_id
 * @property int|null $responsavel_id
 * @property int $criado_por_id
 * @property string $titulo
 * @property EventType $tipo
 * @property EventStatus $status
 * @property EventDuration $duracao
 * @property Carbon $inicio_em
 * @property Carbon $fim_em
 * @property string|null $local
 * @property string|null $descricao
 * @property string|null $observacoes
 */
class Event extends TenantModel
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, SoftDeletes;

    // Tabela já publicada em português (ver AGENTS.md): só o nome da classe
    // segue a convenção em inglês, o schema não muda.
    protected $table = 'eventos';

    /** @return BelongsTo<User, $this> */
    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id')->withTrashed();
    }

    /** @return BelongsToMany<User, $this> */
    public function participantesUsuarios(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'evento_participantes_usuarios',
            'evento_id',
            'usuario_id',
        )->withTimestamps();
    }

    /** @return BelongsToMany<Citizen, $this> */
    public function participantesCidadaos(): BelongsToMany
    {
        return $this->belongsToMany(
            Citizen::class,
            'evento_participantes_cidadaos',
            'evento_id',
            'cidadao_id',
        )->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'tipo' => EventType::class,
            'status' => EventStatus::class,
            'duracao' => EventDuration::class,
            'inicio_em' => 'datetime',
            'fim_em' => 'datetime',
        ];
    }
}

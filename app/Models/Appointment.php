<?php

namespace App\Models;

use App\Enums\AppointmentRecurrence;
use App\Enums\AppointmentStatus;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $gabinete_id
 * @property int|null $responsavel_id
 * @property int|null $cidadao_id
 * @property int|null $demanda_id
 * @property int $criado_por_id
 * @property string $titulo
 * @property Carbon $inicio_em
 * @property Carbon $fim_em
 * @property bool $dia_inteiro
 * @property AppointmentStatus $status
 * @property AppointmentRecurrence $recorrencia
 * @property Carbon|null $recorrencia_ate
 * @property string|null $descricao
 * @property string|null $local
 * @property string $tipo
 * @property string|null $observacoes
 * @property-read User|null $responsavel
 * @property-read User $criadoPor
 * @property-read Cidadao|null $cidadao
 * @property-read Demanda|null $demanda
 * @property-read Collection<int, User> $participantes
 * @property-read Collection<int, AppointmentReminder> $lembretes
 */
class Appointment extends TenantModel
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'compromissos';

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

    /** @return BelongsTo<Cidadao, $this> */
    public function cidadao(): BelongsTo
    {
        return $this->belongsTo(Cidadao::class)->withTrashed();
    }

    /** @return BelongsTo<Demanda, $this> */
    public function demanda(): BelongsTo
    {
        return $this->belongsTo(Demanda::class)->withTrashed();
    }

    /** @return BelongsToMany<User, $this> */
    public function participantes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'compromisso_participantes', 'compromisso_id', 'usuario_id')
            ->withTimestamps();
    }

    /** @return HasMany<AppointmentReminder, $this> */
    public function lembretes(): HasMany
    {
        return $this->hasMany(AppointmentReminder::class, 'compromisso_id');
    }

    public function conflictsWith(Carbon $start, Carbon $end): bool
    {
        return $this->inicio_em->lt($end) && $this->fim_em->gt($start);
    }

    protected function casts(): array
    {
        return [
            'inicio_em' => 'datetime',
            'fim_em' => 'datetime',
            'dia_inteiro' => 'boolean',
            'status' => AppointmentStatus::class,
            'recorrencia' => AppointmentRecurrence::class,
            'recorrencia_ate' => 'date',
        ];
    }
}

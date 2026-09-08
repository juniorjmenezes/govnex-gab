<?php

namespace App\Models;

use App\Enums\ReminderChannel;
use App\Enums\ReminderStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $gabinete_id
 * @property int $compromisso_id
 * @property ReminderChannel $canal
 * @property int $antecedencia_minutos
 * @property array<int, int|string> $destinatarios
 * @property bool $ativo
 * @property Carbon $agendado_para
 * @property ReminderStatus $status
 * @property Carbon|null $processado_em
 * @property string|null $erro
 * @property-read Appointment $compromisso
 * @property-read Collection<int, NotificationAttempt> $tentativas
 */
class AppointmentReminder extends TenantModel
{
    protected $table = 'compromisso_lembretes';

    protected static function booted(): void
    {
        static::creating(function (AppointmentReminder $reminder): void {
            if (! $reminder->gabinete_id && $reminder->compromisso_id) {
                $reminder->gabinete_id = Appointment::withoutGlobalScopes()
                    ->findOrFail($reminder->compromisso_id)
                    ->gabinete_id;
            }
        });
    }

    /** @return BelongsTo<Appointment, $this> */
    public function compromisso(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'compromisso_id');
    }

    /** @return HasMany<NotificationAttempt, $this> */
    public function tentativas(): HasMany
    {
        return $this->hasMany(NotificationAttempt::class, 'lembrete_id');
    }

    protected function casts(): array
    {
        return [
            'canal' => ReminderChannel::class,
            'destinatarios' => 'array',
            'ativo' => 'boolean',
            'agendado_para' => 'datetime',
            'status' => ReminderStatus::class,
            'processado_em' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\ReminderChannel;
use App\Enums\ReminderStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $gabinete_id
 * @property int $lembrete_id
 * @property string $idempotency_key
 * @property ReminderChannel $canal
 * @property string $destinatario
 * @property ReminderStatus $status
 * @property array<string, mixed> $payload
 * @property int $tentativas
 * @property Carbon|null $iniciado_em
 * @property Carbon|null $concluido_em
 * @property string|null $erro
 */
class NotificationAttempt extends TenantModel
{
    protected $table = 'notificacao_tentativas';

    /** @return BelongsTo<AppointmentReminder, $this> */
    public function lembrete(): BelongsTo
    {
        return $this->belongsTo(AppointmentReminder::class, 'lembrete_id');
    }

    protected function casts(): array
    {
        return [
            'canal' => ReminderChannel::class,
            'status' => ReminderStatus::class,
            'payload' => 'array',
            'iniciado_em' => 'datetime',
            'concluido_em' => 'datetime',
        ];
    }
}

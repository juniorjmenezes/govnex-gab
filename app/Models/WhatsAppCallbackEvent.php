<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_id
 * @property int|null $gabinete_id
 * @property int|null $whatsapp_notificacao_id
 * @property string|null $client_request_id
 * @property string $tipo
 * @property string $payload_hash
 * @property string $status
 * @property Carbon|null $ocorrido_em
 * @property Carbon|null $processado_em
 * @property string|null $erro
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WhatsAppCallbackEvent extends Model
{
    protected $table = 'whatsapp_callback_eventos';

    protected $guarded = [];

    /** @return BelongsTo<WhatsAppNotification, $this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(WhatsAppNotification::class, 'whatsapp_notificacao_id');
    }

    protected function casts(): array
    {
        return [
            'ocorrido_em' => 'datetime',
            'processado_em' => 'datetime',
        ];
    }
}

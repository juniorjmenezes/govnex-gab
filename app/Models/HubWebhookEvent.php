<?php

namespace App\Models;

use App\Enums\HubWebhookStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Caixa de entrada durável dos webhooks do Hub. A idempotência é o UNIQUE de
 * `evento_id` (o `id` do evento no Hub), não cache. O `payload` é o evento
 * validado, guardado para reprocesso; tem dados pessoais e é apagado pela
 * retenção (`hub:limpar-webhooks`). Nunca vai para log.
 *
 * @property int $id
 * @property string $evento_id
 * @property string $tipo
 * @property string $sistema
 * @property HubWebhookStatus $status
 * @property int $tentativas
 * @property array<string, mixed> $payload
 * @property string|null $resultado
 * @property string|null $ultimo_erro
 * @property Carbon|null $ocorrido_em
 * @property Carbon $recebido_em
 * @property Carbon|null $ultima_tentativa_em
 * @property Carbon|null $proxima_tentativa_em
 * @property Carbon|null $processado_em
 */
class HubWebhookEvent extends Model
{
    protected $table = 'hub_webhook_eventos';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => HubWebhookStatus::class,
            'tentativas' => 'integer',
            'payload' => 'array',
            'ocorrido_em' => 'datetime',
            'recebido_em' => 'datetime',
            'ultima_tentativa_em' => 'datetime',
            'proxima_tentativa_em' => 'datetime',
            'processado_em' => 'datetime',
        ];
    }
}

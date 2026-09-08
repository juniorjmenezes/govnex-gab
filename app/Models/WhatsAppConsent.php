<?php

namespace App\Models;

use App\Enums\WhatsAppConsentAction;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $gabinete_id
 * @property int $whatsapp_contato_id
 * @property int|null $registrado_por_id
 * @property WhatsAppConsentAction $acao
 * @property string $versao
 * @property string $texto_hash
 * @property string $origem
 * @property Carbon $ocorrido_em
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WhatsAppConsent extends TenantModel
{
    protected $table = 'whatsapp_consentimentos';

    /** @return BelongsTo<WhatsAppContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contato_id');
    }

    /** @return BelongsTo<User, $this> */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por_id');
    }

    protected function casts(): array
    {
        return [
            'acao' => WhatsAppConsentAction::class,
            'ocorrido_em' => 'datetime',
        ];
    }
}

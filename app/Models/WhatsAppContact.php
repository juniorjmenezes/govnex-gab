<?php

namespace App\Models;

use App\Enums\WhatsAppConsentAction;
use App\Enums\WhatsAppContactStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $entidade_id
 * @property int $gabinete_id
 * @property int|null $usuario_id
 * @property int|null $cidadao_id
 * @property string|null $telefone_criptografado
 * @property string|null $telefone_hash
 * @property string|null $telefone_final
 * @property WhatsAppContactStatus $status
 * @property bool $piloto
 * @property string $origem
 * @property Carbon|null $declarado_em
 * @property Carbon|null $revogado_em
 * @property Carbon|null $invalido_em
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Cidadao|null $citizen
 * @property-read Collection<int, WhatsAppConsent> $consents
 */
class WhatsAppContact extends TenantModel
{
    protected $table = 'whatsapp_contatos';

    protected $hidden = ['telefone_criptografado', 'telefone_hash'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id')->withTrashed();
    }

    /** @return BelongsTo<Cidadao, $this> */
    public function citizen(): BelongsTo
    {
        return $this->belongsTo(Cidadao::class, 'cidadao_id')->withTrashed();
    }

    /** @return HasMany<WhatsAppConsent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(WhatsAppConsent::class, 'whatsapp_contato_id');
    }

    public function isEligible(): bool
    {
        return $this->status === WhatsAppContactStatus::Declared
            && trim((string) $this->telefone_criptografado) !== ''
            && trim((string) $this->telefone_hash) !== ''
            && $this->revogado_em === null
            && $this->hasCurrentConsent();
    }

    /** @phpstan-impure */
    public function hasCurrentConsent(): bool
    {
        $latest = $this->consents()->latest('ocorrido_em')->latest('id')->first();

        return $latest?->acao === WhatsAppConsentAction::Accepted
            && hash_equals((string) config('whatsapp.consent.version'), (string) $latest->versao)
            && hash_equals(
                hash('sha256', (string) config('whatsapp.consent.text')),
                (string) $latest->texto_hash,
            );
    }

    protected function casts(): array
    {
        return [
            'telefone_criptografado' => 'encrypted',
            'status' => WhatsAppContactStatus::class,
            'piloto' => 'boolean',
            'declarado_em' => 'datetime',
            'revogado_em' => 'datetime',
            'invalido_em' => 'datetime',
        ];
    }
}

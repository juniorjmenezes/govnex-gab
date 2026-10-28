<?php

namespace App\Models;

use App\Enums\EntityWhatsAppConnectionStatus;
use App\Enums\EntityWhatsAppConnectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $entidade_id
 * @property EntityWhatsAppConnectionType $tipo
 * @property EntityWhatsAppConnectionStatus $status
 * @property bool $ativo
 * @property array<string, mixed>|null $metadados
 * @property Carbon|null $atribuido_em
 * @property Carbon|null $sincronizado_em
 * @property Carbon|null $desativado_em
 */
class EntityWhatsAppConnection extends Model
{
    protected $table = 'entidade_whatsapp_conexoes';

    protected $guarded = [];

    /** @return BelongsTo<Entity, $this> */
    public function entidade(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entidade_id');
    }

    /** @return HasMany<WhatsAppConfiguration, $this> */
    public function configuracoes(): HasMany
    {
        return $this->hasMany(WhatsAppConfiguration::class, 'entidade_whatsapp_conexao_id');
    }

    /** @return HasMany<WhatsAppTemplatePurpose, $this> */
    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplatePurpose::class, 'entidade_whatsapp_conexao_id');
    }

    public function isReady(): bool
    {
        return $this->ativo && $this->status->permitsSending();
    }

    protected function casts(): array
    {
        return [
            'tipo' => EntityWhatsAppConnectionType::class,
            'status' => EntityWhatsAppConnectionStatus::class,
            'ativo' => 'boolean',
            'metadados' => 'array',
            'atribuido_em' => 'datetime',
            'sincronizado_em' => 'datetime',
            'desativado_em' => 'datetime',
        ];
    }
}

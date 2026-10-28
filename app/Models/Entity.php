<?php

namespace App\Models;

use App\Enums\EntityStatus;
use App\Enums\EntityType;
use Database\Factories\EntityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property EntityType $tipo
 * @property string $nome
 * @property string $slug
 * @property EntityStatus $status
 * @property string $municipio
 * @property string $estado
 * @property string $timezone
 * @property string|null $logo_path
 * @property string|null $cor_principal
 * @property string|null $cor_secundaria
 * @property bool $interface_simplificada
 * @property string|null $hub_entidade_id
 * @property Carbon|null $hub_sincronizado_em
 * @property Carbon|null $suspensa_em
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Entity extends Model
{
    /** @use HasFactory<EntityFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'entidades';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<Office, $this> */
    public function gabinetes(): HasMany
    {
        return $this->hasMany(Office::class, 'entidade_id');
    }

    /** @return HasMany<EntityMember, $this> */
    public function membros(): HasMany
    {
        return $this->hasMany(EntityMember::class, 'entidade_id');
    }

    /** @return HasMany<EntityModuleSubscription, $this> */
    public function modulos(): HasMany
    {
        return $this->hasMany(EntityModuleSubscription::class, 'entidade_id');
    }

    /** @return HasMany<EntityLicense, $this> */
    public function licencas(): HasMany
    {
        return $this->hasMany(EntityLicense::class, 'entidade_id');
    }

    /** @return HasMany<EntityInvitation, $this> */
    public function convites(): HasMany
    {
        return $this->hasMany(EntityInvitation::class, 'entidade_id');
    }

    /** @return HasMany<EntityNeighborhood, $this> */
    public function bairros(): HasMany
    {
        return $this->hasMany(EntityNeighborhood::class, 'entidade_id');
    }

    /** @return HasMany<EntityWhatsAppConnection, $this> */
    public function conexoesWhatsApp(): HasMany
    {
        return $this->hasMany(EntityWhatsAppConnection::class, 'entidade_id');
    }

    /** @return HasMany<OfficeTransfer, $this> */
    public function transferenciasEnviadas(): HasMany
    {
        return $this->hasMany(OfficeTransfer::class, 'entidade_origem_id');
    }

    /** @return HasMany<OfficeTransfer, $this> */
    public function transferenciasRecebidas(): HasMany
    {
        return $this->hasMany(OfficeTransfer::class, 'entidade_destino_id');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    protected function casts(): array
    {
        return [
            'tipo' => EntityType::class,
            'status' => EntityStatus::class,
            'interface_simplificada' => 'boolean',
            'configuracoes' => 'array',
            'suspensa_em' => 'datetime',
            'hub_sincronizado_em' => 'datetime',
        ];
    }
}

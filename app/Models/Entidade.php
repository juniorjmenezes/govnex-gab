<?php

namespace App\Models;

use App\Enums\EntidadeStatus;
use App\Enums\EntidadeType;
use Database\Factories\EntidadeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property EntidadeType $tipo
 * @property string $nome
 * @property string $slug
 * @property EntidadeStatus $status
 * @property string $municipio
 * @property string $estado
 * @property string $timezone
 * @property string|null $logo_path
 * @property string|null $cor_principal
 * @property string|null $cor_secundaria
 * @property bool $interface_simplificada
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Entidade extends Model
{
    /** @use HasFactory<EntidadeFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'entidades';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<Gabinete, $this> */
    public function gabinetes(): HasMany
    {
        return $this->hasMany(Gabinete::class, 'entidade_id');
    }

    /** @return HasMany<EntidadeMembro, $this> */
    public function membros(): HasMany
    {
        return $this->hasMany(EntidadeMembro::class, 'entidade_id');
    }

    /** @return HasMany<EntidadeModulo, $this> */
    public function modulos(): HasMany
    {
        return $this->hasMany(EntidadeModulo::class, 'entidade_id');
    }

    /** @return HasMany<EntidadeLicenca, $this> */
    public function licencas(): HasMany
    {
        return $this->hasMany(EntidadeLicenca::class, 'entidade_id');
    }

    /** @return HasMany<EntidadeConvite, $this> */
    public function convites(): HasMany
    {
        return $this->hasMany(EntidadeConvite::class, 'entidade_id');
    }

    /** @return HasMany<EntidadeBairro, $this> */
    public function bairros(): HasMany
    {
        return $this->hasMany(EntidadeBairro::class, 'entidade_id');
    }

    /** @return HasMany<EntidadeWhatsAppConexao, $this> */
    public function conexoesWhatsApp(): HasMany
    {
        return $this->hasMany(EntidadeWhatsAppConexao::class, 'entidade_id');
    }

    /** @return HasMany<GabineteTransferencia, $this> */
    public function transferenciasEnviadas(): HasMany
    {
        return $this->hasMany(GabineteTransferencia::class, 'entidade_origem_id');
    }

    /** @return HasMany<GabineteTransferencia, $this> */
    public function transferenciasRecebidas(): HasMany
    {
        return $this->hasMany(GabineteTransferencia::class, 'entidade_destino_id');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    protected function casts(): array
    {
        return [
            'tipo' => EntidadeType::class,
            'status' => EntidadeStatus::class,
            'interface_simplificada' => 'boolean',
            'configuracoes' => 'array',
            'suspensa_em' => 'datetime',
        ];
    }
}

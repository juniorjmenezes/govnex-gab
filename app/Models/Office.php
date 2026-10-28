<?php

namespace App\Models;

use App\Enums\OfficeStatus;
use App\Enums\OfficeType;
use App\Models\Scopes\OfficeVisibilityScope;
use Database\Factories\OfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $entidade_id
 * @property OfficeType $tipo_gabinete
 * @property string $nome
 * @property string $slug
 * @property OfficeStatus $status
 * @property string|null $vereador_nome
 * @property string|null $numero_eleitoral
 * @property string $municipio
 * @property string $estado
 * @property int|null $municipio_eleitoral_id
 * @property int|null $candidato_titular_id
 * @property string|null $timezone
 * @property string|null $hub_unidade_id
 * @property Carbon|null $hub_sincronizado_em
 * @property Carbon|null $suspended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ElectoralMunicipality|null $municipioEleitoral
 * @property-read Candidate|null $candidatoTitular
 */
#[Fillable([
    'nome',
    'entidade_id',
    'tipo_gabinete',
    'slug',
    'status',
    'vereador_nome',
    'numero_eleitoral',
    'municipio',
    'estado',
    'timezone',
    'telefone',
    'email',
    'endereco',
    'numero',
    'complemento',
    'bairro',
    'cep',
    'logo_path',
    'cor_principal',
    'formato_protocolo',
    'cabecalho_relatorios',
])]
#[ScopedBy([OfficeVisibilityScope::class])]
class Office extends Model
{
    /** @use HasFactory<OfficeFactory> */
    use HasFactory, SoftDeletes;

    // Tabela já publicada em português (ver AGENTS.md): só o nome da classe
    // segue a convenção em inglês, o schema não muda.
    protected $table = 'gabinetes';

    /** @return BelongsTo<Entity, $this> */
    public function entidade(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entidade_id');
    }

    /** @return HasMany<OfficeTransfer, $this> */
    public function transferencias(): HasMany
    {
        return $this->hasMany(OfficeTransfer::class, 'gabinete_id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'gabinete_id');
    }

    /** @return HasMany<OfficeMember, $this> */
    public function membros(): HasMany
    {
        return $this->hasMany(OfficeMember::class, 'gabinete_id');
    }

    /** @return HasMany<OfficeModuleSubscription, $this> */
    public function modulos(): HasMany
    {
        return $this->hasMany(OfficeModuleSubscription::class, 'gabinete_id');
    }

    /** @return HasMany<OfficeModuleEvent, $this> */
    public function moduloEventos(): HasMany
    {
        return $this->hasMany(OfficeModuleEvent::class, 'gabinete_id');
    }

    /** @return BelongsTo<ElectoralMunicipality, $this> */
    public function municipioEleitoral(): BelongsTo
    {
        return $this->belongsTo(ElectoralMunicipality::class);
    }

    /** @return HasMany<TseSync, $this> */
    public function sincronizacoesTse(): HasMany
    {
        return $this->hasMany(TseSync::class, 'gabinete_id');
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidatoTitular(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidato_titular_id');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** @return Attribute<?string, ?string> */
    protected function telefone(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                $digits = preg_replace('/\D+/', '', $value ?? '');

                return $digits !== '' ? $digits : null;
            },
        );
    }

    /** @return Attribute<?string, ?string> */
    protected function numeroEleitoral(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                $digits = preg_replace('/\D+/', '', $value ?? '');

                return $digits !== '' ? $digits : null;
            },
        );
    }

    protected function casts(): array
    {
        return [
            'status' => OfficeStatus::class,
            'tipo_gabinete' => OfficeType::class,
            'suspended_at' => 'datetime',
            'hub_sincronizado_em' => 'datetime',
        ];
    }
}

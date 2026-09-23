<?php

namespace App\Models;

use App\Enums\GabineteStatus;
use App\Enums\GabineteType;
use App\Models\Scopes\GabineteVisibilityScope;
use Database\Factories\GabineteFactory;
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
 * @property GabineteType $tipo_gabinete
 * @property string $nome
 * @property string $slug
 * @property GabineteStatus $status
 * @property string|null $vereador_nome
 * @property string|null $numero_eleitoral
 * @property string $municipio
 * @property string $estado
 * @property int|null $municipio_eleitoral_id
 * @property int|null $candidato_titular_id
 * @property string|null $timezone
 * @property string|null $hub_unidade_id
 * @property Carbon|null $suspended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MunicipioEleitoral|null $municipioEleitoral
 * @property-read CandidatoPolitico|null $candidatoTitular
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
#[ScopedBy([GabineteVisibilityScope::class])]
class Gabinete extends Model
{
    /** @use HasFactory<GabineteFactory> */
    use HasFactory, SoftDeletes;

    /** @return BelongsTo<Entidade, $this> */
    public function entidade(): BelongsTo
    {
        return $this->belongsTo(Entidade::class, 'entidade_id');
    }

    /** @return HasMany<GabineteTransferencia, $this> */
    public function transferencias(): HasMany
    {
        return $this->hasMany(GabineteTransferencia::class, 'gabinete_id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<GabineteMembro, $this> */
    public function membros(): HasMany
    {
        return $this->hasMany(GabineteMembro::class, 'gabinete_id');
    }

    /** @return HasMany<GabineteModulo, $this> */
    public function modulos(): HasMany
    {
        return $this->hasMany(GabineteModulo::class);
    }

    /** @return HasMany<GabineteModuloEvento, $this> */
    public function moduloEventos(): HasMany
    {
        return $this->hasMany(GabineteModuloEvento::class);
    }

    /** @return BelongsTo<MunicipioEleitoral, $this> */
    public function municipioEleitoral(): BelongsTo
    {
        return $this->belongsTo(MunicipioEleitoral::class);
    }

    /** @return HasMany<SincronizacaoTse, $this> */
    public function sincronizacoesTse(): HasMany
    {
        return $this->hasMany(SincronizacaoTse::class);
    }

    /** @return BelongsTo<CandidatoPolitico, $this> */
    public function candidatoTitular(): BelongsTo
    {
        return $this->belongsTo(CandidatoPolitico::class, 'candidato_titular_id');
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
            'status' => GabineteStatus::class,
            'tipo_gabinete' => GabineteType::class,
            'suspended_at' => 'datetime',
        ];
    }
}

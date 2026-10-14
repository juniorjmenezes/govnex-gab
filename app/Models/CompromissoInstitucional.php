<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\GabineteType;
use Database\Factories\CompromissoInstitucionalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Compromisso da Câmara publicado pela Secretaria legislativa e exibido na
 * agenda dos gabinetes de vereador (sessões, reuniões de comissão,
 * convocações).
 *
 * Pertence à entidade, não a um gabinete: por isso fica fora de
 * `BelongsToGabinete`/`TenantModel` (o escopo global exige `gabinete_id` igual
 * ao do contexto) e toda leitura passa por `visibleTo()`. Também não usa
 * `HasTieredVisibility`: aquele trait decide por `gabinete_id`/`entidade_id`,
 * e aqui não existe nível "privado de um gabinete" nem "plataforma" — o
 * compromisso é sempre da Câmara, e o recorte de público é a tabela de alvos.
 *
 * Público (`compromisso_institucional_gabinetes`): sem linha, vale para todos
 * os gabinetes de vereador da Câmara; com linhas, só para os listados. O
 * gabinete que publicou sempre enxerga o que publicou.
 *
 * Reunião de comissão (`comissao_id`): o público é um retrato dos membros
 * ativos da comissão tirado por `InstitutionalAudienceService` ao vincular, e
 * não é recalculado depois. Por isso a leitura continua só na tabela de alvos.
 *
 * @property int $id
 * @property int $entidade_id
 * @property int $criado_por_gabinete_id
 * @property int $criado_por_id
 * @property int|null $comissao_id
 * @property string $titulo
 * @property string|null $descricao
 * @property Carbon $inicio_em
 * @property Carbon $fim_em
 * @property bool $dia_inteiro
 * @property string|null $local
 * @property string $tipo
 * @property AppointmentStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Gabinete $criadoPorGabinete
 * @property-read User $criadoPor
 * @property-read ComissaoLegislativa|null $comissao
 * @property-read Collection<int, Gabinete> $gabinetesAlvo
 */
#[Fillable(['titulo', 'descricao', 'inicio_em', 'fim_em', 'dia_inteiro', 'local', 'tipo', 'status'])]
class CompromissoInstitucional extends Model
{
    /** @use HasFactory<CompromissoInstitucionalFactory> */
    use HasFactory, SoftDeletes;

    public const TARGETS_TABLE = 'compromisso_institucional_gabinetes';

    protected $table = 'compromissos_institucionais';

    /**
     * O que o gabinete enxerga: compromissos da entidade dele em que é alvo,
     * os sem alvo (se for gabinete de vereador) e os que ele mesmo publicou.
     * A entidade vem do próprio gabinete, nunca da requisição.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, Gabinete $gabinete): Builder
    {
        $targets = self::TARGETS_TABLE;
        $key = $query->qualifyColumn('id');

        return $query
            ->where($query->qualifyColumn('entidade_id'), $gabinete->entidade_id)
            ->where(function (Builder $audience) use ($gabinete, $targets, $key): void {
                $audience->where($audience->qualifyColumn('criado_por_gabinete_id'), $gabinete->id)
                    ->orWhereExists(fn (QueryBuilder $target) => $target
                        ->selectRaw('1')
                        ->from($targets)
                        ->whereColumn("{$targets}.compromisso_institucional_id", $key)
                        ->where("{$targets}.gabinete_id", $gabinete->id))
                    ->when(
                        $gabinete->tipo_gabinete === GabineteType::CouncilorOffice,
                        fn (Builder $everyone) => $everyone->orWhereNotExists(fn (QueryBuilder $target) => $target
                            ->selectRaw('1')
                            ->from($targets)
                            ->whereColumn("{$targets}.compromisso_institucional_id", $key)),
                    );
            });
    }

    public function isPublishedBy(Gabinete|int|null $gabinete): bool
    {
        $id = $gabinete instanceof Gabinete ? $gabinete->id : $gabinete;

        return $id !== null && $this->criado_por_gabinete_id === $id;
    }

    /** @return BelongsTo<Entidade, $this> */
    public function entidade(): BelongsTo
    {
        return $this->belongsTo(Entidade::class, 'entidade_id');
    }

    /**
     * Sem o escopo global: no contexto de um gabinete de vereador, o
     * `Gabinete` só enxerga o próprio, e quem publicou é a Secretaria.
     *
     * @return BelongsTo<Gabinete, $this>
     */
    public function criadoPorGabinete(): BelongsTo
    {
        return $this->belongsTo(Gabinete::class, 'criado_por_gabinete_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id')->withTrashed();
    }

    /**
     * Comissão cuja reunião este compromisso marca. Comissão excluída (soft
     * delete) some da relação, mas o público gravado continua valendo.
     *
     * @return BelongsTo<ComissaoLegislativa, $this>
     */
    public function comissao(): BelongsTo
    {
        return $this->belongsTo(ComissaoLegislativa::class, 'comissao_id');
    }

    /**
     * Gabinetes-alvo. Sem o escopo global de `Gabinete`, pelo mesmo motivo
     * de `criadoPorGabinete()`: a lista é de gabinetes de outros contextos.
     *
     * @return BelongsToMany<Gabinete, $this>
     */
    public function gabinetesAlvo(): BelongsToMany
    {
        return $this->belongsToMany(
            Gabinete::class,
            self::TARGETS_TABLE,
            'compromisso_institucional_id',
            'gabinete_id',
        )->withoutGlobalScopes()->withPivot('confirmado_em')->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'comissao_id' => 'integer',
            'inicio_em' => 'datetime',
            'fim_em' => 'datetime',
            'dia_inteiro' => 'boolean',
        ];
    }
}

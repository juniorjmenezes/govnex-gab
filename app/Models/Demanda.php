<?php

namespace App\Models;

use App\Enums\DemandOrigin;
use App\Enums\DemandPriority;
use App\Enums\DemandResultado;
use App\Enums\DemandStatus;
use Database\Factories\DemandaFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $gabinete_id
 * @property string $protocolo
 * @property int $cidadao_id
 * @property int $categoria_id
 * @property int|null $bairro_id
 * @property int|null $responsavel_id
 * @property int $criado_por_id
 * @property string $titulo
 * @property string $descricao
 * @property DemandPriority $prioridade
 * @property DemandStatus $status
 * @property DemandOrigin $origem
 * @property DemandResultado|null $resultado
 * @property Carbon $aberta_em
 * @property Carbon|null $prazo
 * @property Carbon|null $concluida_em
 * @property Carbon|null $encerrada_em
 * @property Carbon|null $ultima_atividade_em
 * @property string|null $proxima_acao_descricao
 * @property Carbon|null $proxima_acao_data
 * @property int|null $proxima_acao_responsavel_id
 * @property Carbon|null $proxima_acao_concluida_em
 * @property Carbon|null $favoritada_em
 * @property int|null $favoritada_por_id
 */
class Demanda extends TenantModel
{
    /** @use HasFactory<DemandaFactory> */
    use HasFactory, SoftDeletes;

    /** @return BelongsTo<Cidadao, $this> */
    public function cidadao(): BelongsTo
    {
        return $this->belongsTo(Cidadao::class)->withTrashed();
    }

    /** @return BelongsTo<Categoria, $this> */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class)->withTrashed();
    }

    /** @return BelongsTo<Bairro, $this> */
    public function bairro(): BelongsTo
    {
        return $this->belongsTo(Bairro::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function proximaAcaoResponsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proxima_acao_responsavel_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function favoritadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'favoritada_por_id')->withTrashed();
    }

    public function isFavoritada(): bool
    {
        return $this->favoritada_em !== null;
    }

    /** @return HasMany<DemandaEvento, $this> */
    public function eventos(): HasMany
    {
        return $this->hasMany(DemandaEvento::class);
    }

    /** @return HasMany<DemandaAnexo, $this> */
    public function anexos(): HasMany
    {
        return $this->hasMany(DemandaAnexo::class);
    }

    /** @return HasMany<Atendimento, $this> */
    public function atendimentos(): HasMany
    {
        return $this->hasMany(Atendimento::class);
    }

    public function isOverdue(): bool
    {
        return $this->prazo !== null
            && $this->prazo->isPast()
            && $this->status->isOpen();
    }

    /**
     * A data é opcional ao definir uma próxima ação — não pode ser exigida
     * aqui para "há algo pendente", ou o botão "Concluir" (que o front-end
     * mostra com base só na descrição) falharia para ações sem prazo.
     */
    public function hasNextActionPending(): bool
    {
        return $this->proxima_acao_descricao !== null && $this->proxima_acao_concluida_em === null;
    }

    public function isNextActionOverdue(): bool
    {
        return $this->hasNextActionPending()
            && $this->proxima_acao_data !== null
            && $this->proxima_acao_data->isPast();
    }

    /** @return Attribute<?string, ?string> */
    protected function cep(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value ? preg_replace('/\\D+/', '', $value) : null);
    }

    /** @return Attribute<?string, ?string> */
    protected function estado(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value ? Str::upper(trim($value)) : null);
    }

    /** @return Attribute<?string, ?string> */
    protected function municipio(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value ? Str::squish($value) : null);
    }

    protected function casts(): array
    {
        return [
            'prioridade' => DemandPriority::class,
            'status' => DemandStatus::class,
            'origem' => DemandOrigin::class,
            'resultado' => DemandResultado::class,
            'aberta_em' => 'datetime',
            'prazo' => 'datetime',
            'concluida_em' => 'datetime',
            'encerrada_em' => 'datetime',
            'ultima_atividade_em' => 'datetime',
            'proxima_acao_data' => 'date',
            'proxima_acao_concluida_em' => 'datetime',
            'favoritada_em' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}

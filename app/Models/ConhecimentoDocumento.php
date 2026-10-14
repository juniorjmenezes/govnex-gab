<?php

namespace App\Models;

use App\Models\Concerns\HasTieredVisibility;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * PDF da Base de Conhecimento, com visibilidade em três níveis
 * (`HasTieredVisibility`): do gabinete, compartilhado com a entidade pela
 * Secretaria legislativa ou da biblioteca da plataforma. Por isso não usa o
 * escopo global de tenant e filtra a visibilidade com `visibleTo()`.
 *
 * @property int $id
 * @property int|null $gabinete_id
 * @property int|null $entidade_id
 * @property string $titulo
 * @property string|null $descricao
 * @property string $disk
 * @property string $caminho
 * @property string $nome_original
 * @property int $tamanho
 * @property int|null $total_paginas
 * @property int $leituras_completas
 * @property int|null $enviado_por_id
 * @property Carbon|null $created_at
 */
#[Hidden(['disk', 'caminho', 'deleted_at'])]
class ConhecimentoDocumento extends Model
{
    use HasTieredVisibility;
    use SoftDeletes;

    protected $table = 'conhecimento_documentos';

    /** @var list<string> */
    protected $guarded = ['id', 'gabinete_id', 'entidade_id'];

    /**
     * De onde o documento vem, para a listagem: biblioteca da plataforma,
     * compartilhado com a entidade (Secretaria legislativa) ou do gabinete.
     *
     * @return 'plataforma'|'entidade'|'gabinete'
     */
    public function origin(): string
    {
        return match (true) {
            $this->isPlatform() => 'plataforma',
            $this->isEntidadeWide() => 'entidade',
            default => 'gabinete',
        };
    }

    /**
     * Só a biblioteca da plataforma (sem gabinete e sem entidade) — a área
     * administrativa não pode listar o compartilhado de uma Câmara.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePlatformLibrary(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('gabinete_id'))
            ->whereNull($query->qualifyColumn('entidade_id'));
    }

    /** @return BelongsTo<Gabinete, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Gabinete::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Entidade, $this> */
    public function entidade(): BelongsTo
    {
        return $this->belongsTo(Entidade::class);
    }

    /** @return BelongsTo<User, $this> */
    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por_id');
    }

    /** @return HasMany<ConhecimentoLeitura, $this> */
    public function leituras(): HasMany
    {
        return $this->hasMany(ConhecimentoLeitura::class, 'documento_id');
    }

    protected function casts(): array
    {
        return [
            'tamanho' => 'integer',
            'total_paginas' => 'integer',
            'leituras_completas' => 'integer',
        ];
    }
}

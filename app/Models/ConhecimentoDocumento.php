<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * PDF da Base de Conhecimento. Sem `gabinete_id`, pertence à biblioteca da
 * plataforma e é visível a todos os gabinetes com o módulo ativo; por isso não
 * usa o escopo global de tenant e filtra a visibilidade com `visibleTo()`.
 *
 * @property int $id
 * @property int|null $gabinete_id
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
    use SoftDeletes;

    protected $table = 'conhecimento_documentos';

    /** @var list<string> */
    protected $guarded = ['id', 'gabinete_id'];

    public function isPlatform(): bool
    {
        return $this->gabinete_id === null;
    }

    /**
     * Biblioteca da plataforma mais os documentos do próprio gabinete.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, int $gabineteId): Builder
    {
        return $query->where(fn (Builder $visible) => $visible
            ->whereNull('gabinete_id')
            ->orWhere('gabinete_id', $gabineteId));
    }

    /** @return BelongsTo<Gabinete, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Gabinete::class)->withoutGlobalScopes();
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

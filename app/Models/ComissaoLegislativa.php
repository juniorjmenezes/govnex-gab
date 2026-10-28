<?php

namespace App\Models;

use App\Enums\CommitteeStatus;
use App\Enums\CommitteeType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Comissão legislativa da Câmara, mantida pela Secretaria legislativa e lida
 * por todos os gabinetes da mesma entidade.
 *
 * Pertence à entidade, não a um gabinete — mesmo padrão de `EntityNeighborhood`:
 * fica fora de `BelongsToOffice`/`TenantModel`, e toda leitura passa por
 * `ofEntidade()` com a entidade do gabinete em contexto (nunca da requisição).
 *
 * Documentos vinculados (`documentos()`) são `KnowledgeDocument`s já
 * compartilhados com a Câmara; o vínculo é só a tabela `comissao_documentos`,
 * sem model próprio.
 *
 * @property int $id
 * @property int $entidade_id
 * @property string $nome
 * @property string|null $sigla
 * @property CommitteeType $tipo
 * @property CommitteeStatus $status
 * @property int $criado_por_id
 * @property CarbonImmutable|null $encerrada_em
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ComissaoMembro> $membros
 * @property-read Collection<int, KnowledgeDocument> $documentos
 */
#[Fillable(['nome', 'sigla', 'tipo', 'status'])]
class ComissaoLegislativa extends Model
{
    use SoftDeletes;

    public const DOCUMENTS_TABLE = 'comissao_documentos';

    /** Tipos de vínculo de documento aceitos (string curta, sem enum). */
    public const DOCUMENT_LINK_TYPES = [
        'pauta' => 'Pauta',
        'parecer' => 'Parecer',
        'ata' => 'Ata',
        'outro' => 'Outro',
    ];

    protected $table = 'comissoes_legislativas';

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfEntidade(Builder $query, int $entidadeId): Builder
    {
        return $query->where($query->qualifyColumn('entidade_id'), $entidadeId);
    }

    public function isActive(): bool
    {
        return $this->status === CommitteeStatus::Active;
    }

    /** @return BelongsTo<Entity, $this> */
    public function entidade(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entidade_id');
    }

    /** @return BelongsTo<User, $this> */
    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id')->withTrashed();
    }

    /** @return HasMany<ComissaoMembro, $this> */
    public function membros(): HasMany
    {
        return $this->hasMany(ComissaoMembro::class, 'comissao_id');
    }

    /** @return BelongsToMany<KnowledgeDocument, $this> */
    public function documentos(): BelongsToMany
    {
        return $this->belongsToMany(
            KnowledgeDocument::class,
            self::DOCUMENTS_TABLE,
            'comissao_id',
            'documento_id',
        )->withPivot('tipo_vinculo')->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'tipo' => CommitteeType::class,
            'status' => CommitteeStatus::class,
            'encerrada_em' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\OfficeTransferStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $gabinete_id
 * @property int $entidade_origem_id
 * @property int $entidade_destino_id
 * @property OfficeTransferStatus $status
 * @property Carbon|null $aceita_destino_em
 * @property Carbon|null $concluida_em
 * @property Carbon|null $encerrada_em
 * @property string|null $motivo_encerramento
 * @property string|null $manifesto_hash
 * @property Carbon|null $created_at
 * @property-read Office|null $gabinete
 * @property-read Entity|null $entidadeOrigem
 * @property-read Entity|null $entidadeDestino
 * @property-read Collection<int,OfficeTransferEvent> $eventos
 */
class OfficeTransfer extends Model
{
    use HasUuids;

    protected $table = 'gabinete_transferencias';

    protected $guarded = [];

    /** @return BelongsTo<Office, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'gabinete_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<Entity, $this> */
    public function entidadeOrigem(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entidade_origem_id');
    }

    /** @return BelongsTo<Entity, $this> */
    public function entidadeDestino(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entidade_destino_id');
    }

    /** @return HasMany<OfficeTransferEvent, $this> */
    public function eventos(): HasMany
    {
        return $this->hasMany(OfficeTransferEvent::class, 'transferencia_id');
    }

    protected function casts(): array
    {
        return [
            'status' => OfficeTransferStatus::class,
            'agendada_para' => 'datetime',
            'aceita_origem_em' => 'datetime',
            'aceita_destino_em' => 'datetime',
            'aprovada_em' => 'datetime',
            'concluida_em' => 'datetime',
            'encerrada_em' => 'datetime',
            'manifesto' => 'array',
        ];
    }
}

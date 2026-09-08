<?php

namespace App\Models;

use App\Enums\GabineteTransferStatus;
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
 * @property GabineteTransferStatus $status
 * @property Carbon|null $aceita_destino_em
 * @property Carbon|null $concluida_em
 * @property Carbon|null $encerrada_em
 * @property string|null $motivo_encerramento
 * @property string|null $manifesto_hash
 * @property Carbon|null $created_at
 * @property-read Gabinete|null $gabinete
 * @property-read Entidade|null $entidadeOrigem
 * @property-read Entidade|null $entidadeDestino
 * @property-read Collection<int,GabineteTransferenciaEvento> $eventos
 */
class GabineteTransferencia extends Model
{
    use HasUuids;

    protected $table = 'gabinete_transferencias';

    protected $guarded = [];

    /** @return BelongsTo<Gabinete, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Gabinete::class, 'gabinete_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<Entidade, $this> */
    public function entidadeOrigem(): BelongsTo
    {
        return $this->belongsTo(Entidade::class, 'entidade_origem_id');
    }

    /** @return BelongsTo<Entidade, $this> */
    public function entidadeDestino(): BelongsTo
    {
        return $this->belongsTo(Entidade::class, 'entidade_destino_id');
    }

    /** @return HasMany<GabineteTransferenciaEvento, $this> */
    public function eventos(): HasMany
    {
        return $this->hasMany(GabineteTransferenciaEvento::class, 'transferencia_id');
    }

    protected function casts(): array
    {
        return [
            'status' => GabineteTransferStatus::class,
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

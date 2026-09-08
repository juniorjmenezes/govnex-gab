<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $transferencia_id
 * @property string $evento
 * @property string|null $estado_anterior
 * @property string|null $estado_novo
 * @property array<string,mixed>|null $contexto
 * @property Carbon $ocorrido_em
 */
class GabineteTransferenciaEvento extends Model
{
    protected $table = 'gabinete_transferencia_eventos';

    protected $guarded = [];

    /** @return BelongsTo<GabineteTransferencia, $this> */
    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(GabineteTransferencia::class, 'transferencia_id');
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Eventos de transferência são imutáveis.'));
        static::deleting(fn (): never => throw new LogicException('Eventos de transferência não podem ser removidos.'));
    }

    protected function casts(): array
    {
        return [
            'contexto' => 'array',
            'ocorrido_em' => 'datetime',
        ];
    }
}

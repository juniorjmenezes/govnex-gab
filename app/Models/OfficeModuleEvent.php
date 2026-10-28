<?php

namespace App\Models;

use App\Enums\OfficeModule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $gabinete_id
 * @property OfficeModule $modulo
 * @property string $acao
 * @property int|null $administrador_id
 * @property array<string, scalar|null> $contexto
 * @property Carbon $ocorrido_em
 * @property-read User|null $administrador
 */
class OfficeModuleEvent extends Model
{
    protected $table = 'gabinete_modulo_eventos';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Eventos de módulos são imutáveis.');
        });

        static::deleting(function (): never {
            throw new LogicException('Eventos de módulos são imutáveis.');
        });
    }

    /** @return BelongsTo<Office, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** @return BelongsTo<User, $this> */
    public function administrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administrador_id');
    }

    protected function casts(): array
    {
        return [
            'modulo' => OfficeModule::class,
            'contexto' => 'array',
            'ocorrido_em' => 'datetime',
        ];
    }
}

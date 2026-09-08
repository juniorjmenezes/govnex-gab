<?php

namespace App\Models;

use App\Enums\GabineteModule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $gabinete_id
 * @property GabineteModule $modulo
 * @property string $acao
 * @property int|null $administrador_id
 * @property array<string, scalar|null> $contexto
 * @property Carbon $ocorrido_em
 * @property-read User|null $administrador
 */
class GabineteModuloEvento extends Model
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

    /** @return BelongsTo<Gabinete, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Gabinete::class);
    }

    /** @return BelongsTo<User, $this> */
    public function administrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administrador_id');
    }

    protected function casts(): array
    {
        return [
            'modulo' => GabineteModule::class,
            'contexto' => 'array',
            'ocorrido_em' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\GabineteModule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $gabinete_id
 * @property GabineteModule $modulo
 * @property bool $ativo
 * @property Carbon|null $ativado_em
 * @property Carbon|null $desativado_em
 * @property int|null $administrador_id
 */
class GabineteModulo extends Model
{
    protected $table = 'gabinete_modulos';

    protected $guarded = [];

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
            'ativo' => 'boolean',
            'ativado_em' => 'datetime',
            'desativado_em' => 'datetime',
        ];
    }
}

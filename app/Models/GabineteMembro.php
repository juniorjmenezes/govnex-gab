<?php

namespace App\Models;

use App\Enums\AccessRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $gabinete_id
 * @property int $usuario_id
 * @property AccessRole $papel
 * @property bool $ativo
 * @property Carbon|null $ingressou_em
 * @property-read User $usuario
 */
class GabineteMembro extends Model
{
    protected $table = 'gabinete_membros';

    protected $guarded = [];

    /** @return BelongsTo<Gabinete, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Gabinete::class, 'gabinete_id');
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    protected function casts(): array
    {
        return [
            'papel' => AccessRole::class,
            'ativo' => 'boolean',
            'ingressou_em' => 'datetime',
            'desativado_em' => 'datetime',
        ];
    }
}

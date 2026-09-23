<?php

namespace App\Models;

use App\Enums\AccessRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $entidade_id
 * @property int $usuario_id
 * @property AccessRole $papel
 * @property bool $ativo
 */
class EntidadeMembro extends Model
{
    protected $table = 'entidade_membros';

    protected $guarded = [];

    /** @return BelongsTo<Entidade, $this> */
    public function entidade(): BelongsTo
    {
        return $this->belongsTo(Entidade::class, 'entidade_id');
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

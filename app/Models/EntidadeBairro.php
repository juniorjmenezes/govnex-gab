<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $entidade_id
 * @property string $nome
 * @property string $municipio
 * @property string $estado
 * @property bool $ativo
 * @property int|null $criado_por
 */
class EntidadeBairro extends Model
{
    protected $table = 'entidade_bairros';

    protected $guarded = [];

    /** @return BelongsTo<Entidade, $this> */
    public function entidade(): BelongsTo
    {
        return $this->belongsTo(Entidade::class, 'entidade_id');
    }

    /** @return BelongsTo<User, $this> */
    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    /** @return HasMany<Bairro, $this> */
    public function bairrosLocais(): HasMany
    {
        return $this->hasMany(Bairro::class, 'entidade_bairro_id');
    }

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }
}

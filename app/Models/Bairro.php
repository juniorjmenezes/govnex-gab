<?php

namespace App\Models;

use Database\Factories\BairroFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bairro extends TenantModel
{
    /** @use HasFactory<BairroFactory> */
    use HasFactory, SoftDeletes;

    /** @return HasMany<Cidadao, $this> */
    public function cidadaos(): HasMany
    {
        return $this->hasMany(Cidadao::class);
    }

    /** @return BelongsTo<EntidadeBairro, $this> */
    public function referenciaEntidade(): BelongsTo
    {
        return $this->belongsTo(EntidadeBairro::class, 'entidade_bairro_id');
    }

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }
}

<?php

namespace App\Models;

use Database\Factories\NeighborhoodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Neighborhood extends TenantModel
{
    /** @use HasFactory<NeighborhoodFactory> */
    use HasFactory, SoftDeletes;

    // Tabela já publicada em português (ver AGENTS.md): só o nome da classe
    // segue a convenção em inglês, o schema não muda.
    protected $table = 'bairros';

    /** @return HasMany<Citizen, $this> */
    public function cidadaos(): HasMany
    {
        return $this->hasMany(Citizen::class, 'bairro_id');
    }

    /** @return BelongsTo<EntityNeighborhood, $this> */
    public function referenciaEntidade(): BelongsTo
    {
        return $this->belongsTo(EntityNeighborhood::class, 'entidade_bairro_id');
    }

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }
}

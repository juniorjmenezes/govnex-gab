<?php

namespace App\Models;

use Database\Factories\CategoriaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Categoria extends TenantModel
{
    /** @use HasFactory<CategoriaFactory> */
    use HasFactory, SoftDeletes;

    /** @return HasMany<Demanda, $this> */
    public function demandas(): HasMany
    {
        return $this->hasMany(Demanda::class);
    }

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }
}

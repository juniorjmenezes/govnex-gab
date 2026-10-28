<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends TenantModel
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, SoftDeletes;

    // Tabela já publicada em português (ver AGENTS.md): só o nome da classe
    // segue a convenção em inglês, o schema não muda.
    protected $table = 'categorias';

    /** @return HasMany<Demand, $this> */
    public function demandas(): HasMany
    {
        return $this->hasMany(Demand::class, 'categoria_id');
    }

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }
}

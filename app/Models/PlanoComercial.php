<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanoComercial extends Model
{
    protected $table = 'planos_comerciais';

    protected $guarded = [];

    /** @return HasMany<PlanoComercialVersao, $this> */
    public function versoes(): HasMany
    {
        return $this->hasMany(PlanoComercialVersao::class, 'plano_comercial_id');
    }

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }
}

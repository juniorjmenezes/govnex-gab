<?php

namespace App\Models;

use App\Enums\EntityQuota;
use Illuminate\Database\Eloquent\Model;

class EntityUsage extends Model
{
    protected $table = 'entidade_consumos';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metrica' => EntityQuota::class, 'quantidade' => 'integer'];
    }
}

<?php

namespace App\Models;

use App\Enums\EntidadeQuota;
use Illuminate\Database\Eloquent\Model;

class EntidadeConsumo extends Model
{
    protected $table = 'entidade_consumos';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metrica' => EntidadeQuota::class, 'quantidade' => 'integer'];
    }
}

<?php

namespace App\Models;

use Database\Factories\ConfiguracaoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Configuracao extends TenantModel
{
    /** @use HasFactory<ConfiguracaoFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'configuracoes';
}

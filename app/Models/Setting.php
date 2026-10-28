<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Setting extends TenantModel
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'configuracoes';
}

<?php

namespace App\Models;

use App\Enums\EntityModule;
use App\Enums\ModuleScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $entidade_id
 * @property EntityModule $modulo
 * @property ModuleScope $escopo
 * @property bool $contratado
 * @property bool $ativo
 * @property Carbon|null $ativado_em
 * @property Carbon|null $desativado_em
 */
class EntityModuleSubscription extends Model
{
    protected $table = 'entidade_modulos';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'modulo' => EntityModule::class,
            'escopo' => ModuleScope::class,
            'contratado' => 'boolean',
            'ativo' => 'boolean',
            'ativado_em' => 'datetime',
            'desativado_em' => 'datetime',
        ];
    }
}

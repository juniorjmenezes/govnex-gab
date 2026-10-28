<?php

namespace App\Models;

use App\Enums\OfficeModule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $gabinete_id
 * @property OfficeModule $modulo
 * @property bool $ativo
 * @property Carbon|null $ativado_em
 * @property Carbon|null $desativado_em
 * @property int|null $administrador_id
 */
class OfficeModuleSubscription extends Model
{
    protected $table = 'gabinete_modulos';

    protected $guarded = [];

    /** @return BelongsTo<Office, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** @return BelongsTo<User, $this> */
    public function administrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administrador_id');
    }

    protected function casts(): array
    {
        return [
            'modulo' => OfficeModule::class,
            'ativo' => 'boolean',
            'ativado_em' => 'datetime',
            'desativado_em' => 'datetime',
        ];
    }
}

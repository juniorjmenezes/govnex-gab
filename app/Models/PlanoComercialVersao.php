<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PlanoComercialVersao extends Model
{
    protected $table = 'plano_comercial_versoes';

    protected $guarded = [];

    /** @return BelongsTo<PlanoComercial, $this> */
    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoComercial::class, 'plano_comercial_id');
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Versões publicadas de planos comerciais são imutáveis.');
        });

        static::deleting(function (): never {
            throw new LogicException('Versões utilizadas de planos comerciais não podem ser removidas.');
        });
    }

    protected function casts(): array
    {
        return [
            'modulos' => 'array',
            'cotas' => 'array',
            'configuracoes' => 'array',
            'publicado_em' => 'datetime',
        ];
    }
}

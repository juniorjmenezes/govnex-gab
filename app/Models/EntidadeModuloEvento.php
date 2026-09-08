<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EntidadeModuloEvento extends Model
{
    protected $table = 'entidade_modulo_eventos';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Eventos de módulos da organização são imutáveis.');
        });

        static::deleting(function (): never {
            throw new LogicException('Eventos de módulos da organização são imutáveis.');
        });
    }

    protected function casts(): array
    {
        return ['contexto' => 'array', 'ocorrido_em' => 'datetime'];
    }
}

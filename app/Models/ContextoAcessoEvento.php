<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ContextoAcessoEvento extends Model
{
    protected $table = 'contexto_acesso_eventos';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Eventos de contexto são imutáveis.');
        });

        static::deleting(function (): never {
            throw new LogicException('Eventos de contexto são imutáveis.');
        });
    }

    protected function casts(): array
    {
        return [
            'administrador_plataforma' => 'boolean',
            'contexto' => 'array',
            'ocorrido_em' => 'datetime',
        ];
    }
}

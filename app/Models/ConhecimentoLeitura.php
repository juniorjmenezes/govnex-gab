<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Progresso de uma pessoa em um documento da Base de Conhecimento.
 *
 * @property int $id
 * @property int $documento_id
 * @property int $usuario_id
 * @property list<int> $paginas_lidas
 * @property int $ultima_pagina
 * @property Carbon|null $concluida_em
 */
class ConhecimentoLeitura extends Model
{
    protected $table = 'conhecimento_leituras';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<ConhecimentoDocumento, $this> */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(ConhecimentoDocumento::class, 'documento_id');
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    protected function casts(): array
    {
        return [
            'paginas_lidas' => 'array',
            'ultima_pagina' => 'integer',
            'concluida_em' => 'datetime',
        ];
    }
}

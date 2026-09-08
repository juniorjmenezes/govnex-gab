<?php

namespace App\Models;

use App\Enums\ElectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ano
 * @property ElectionType $tipo
 * @property string $nome
 * @property Carbon $primeiro_turno_em
 * @property Carbon|null $segundo_turno_em
 * @property Carbon|null $fonte_atualizada_em
 */
class Eleicao extends Model
{
    protected $table = 'eleicoes';

    protected $guarded = [];

    /** @return HasMany<CandidatoPolitico, $this> */
    public function candidatos(): HasMany
    {
        return $this->hasMany(CandidatoPolitico::class);
    }

    protected function casts(): array
    {
        return [
            'tipo' => ElectionType::class,
            'primeiro_turno_em' => 'date',
            'segundo_turno_em' => 'date',
            'fonte_atualizada_em' => 'datetime',
        ];
    }
}

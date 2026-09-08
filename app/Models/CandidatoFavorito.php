<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidatoFavorito extends TenantModel
{
    protected $table = 'candidatos_favoritos';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'termos_busca' => 'array',
            'termos_exclusao' => 'array',
        ];
    }

    /** @return BelongsTo<CandidatoPolitico, $this> */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(CandidatoPolitico::class, 'candidato_politico_id');
    }

    /** @return BelongsTo<User, $this> */
    public function escolhidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escolhido_por_id')->withTrashed();
    }
}

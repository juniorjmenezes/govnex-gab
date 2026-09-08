<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $municipio_eleitoral_id
 * @property int $ano_referencia
 * @property Carbon $data_referencia
 * @property int $eleitores_aptos
 * @property string $fonte_url
 * @property Carbon|null $fonte_gerada_em
 */
class EleitoradoMunicipioSnapshot extends Model
{
    protected $table = 'eleitorado_municipio_snapshots';

    protected $guarded = [];

    /** @return BelongsTo<MunicipioEleitoral, $this> */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(MunicipioEleitoral::class, 'municipio_eleitoral_id');
    }

    protected function casts(): array
    {
        return [
            'data_referencia' => 'date',
            'fonte_gerada_em' => 'datetime',
        ];
    }
}

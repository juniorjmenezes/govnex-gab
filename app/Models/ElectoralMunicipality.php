<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $codigo_tse
 * @property string|null $codigo_ibge
 * @property string $nome
 * @property string $uf
 */
class ElectoralMunicipality extends Model
{
    protected $table = 'municipios_eleitorais';

    protected $guarded = [];

    /** @return HasMany<ElectorateSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(ElectorateSnapshot::class, 'municipio_eleitoral_id');
    }

    /** @return HasMany<MunicipalElectionTurnout, $this> */
    public function comparecimentos(): HasMany
    {
        return $this->hasMany(MunicipalElectionTurnout::class, 'municipio_eleitoral_id');
    }
}

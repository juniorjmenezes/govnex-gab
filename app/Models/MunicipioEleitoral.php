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
class MunicipioEleitoral extends Model
{
    protected $table = 'municipios_eleitorais';

    protected $guarded = [];

    /** @return HasMany<EleitoradoMunicipioSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(EleitoradoMunicipioSnapshot::class);
    }

    /** @return HasMany<ComparecimentoEleitoralMunicipio, $this> */
    public function comparecimentos(): HasMany
    {
        return $this->hasMany(ComparecimentoEleitoralMunicipio::class);
    }
}

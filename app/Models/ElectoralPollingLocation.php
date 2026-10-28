<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $eleicao_id
 * @property int $municipio_eleitoral_id
 * @property string $nr_zona
 * @property string $nr_local_votacao
 * @property string $nome
 * @property string|null $tipo_local
 * @property string|null $endereco
 * @property string|null $bairro
 * @property string|null $cep
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string|null $latitude_fonte
 * @property Carbon|null $geocodificado_em
 * @property string $fonte_url
 * @property Carbon|null $fonte_gerada_em
 * @property-read Election $eleicao
 * @property-read ElectoralMunicipality $municipioEleitoral
 */
class ElectoralPollingLocation extends Model
{
    protected $table = 'locais_votacao_eleitorais';

    protected $guarded = [];

    /** @return BelongsTo<Election, $this> */
    public function eleicao(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'eleicao_id');
    }

    /** @return BelongsTo<ElectoralMunicipality, $this> */
    public function municipioEleitoral(): BelongsTo
    {
        return $this->belongsTo(ElectoralMunicipality::class, 'municipio_eleitoral_id');
    }

    /** @return HasMany<ElectoralSection, $this> */
    public function secoes(): HasMany
    {
        return $this->hasMany(ElectoralSection::class, 'local_votacao_eleitoral_id');
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geocodificado_em' => 'datetime',
            'fonte_gerada_em' => 'datetime',
        ];
    }
}

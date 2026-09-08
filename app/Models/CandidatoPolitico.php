<?php

namespace App\Models;

use App\Enums\CandidateScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $eleicao_id
 * @property CandidateScope $abrangencia
 * @property int|null $municipio_eleitoral_id
 * @property string|null $uf
 * @property string $cargo
 * @property string $nome
 * @property string $nome_urna
 * @property string|null $numero
 * @property string|null $partido_sigla
 * @property string|null $partido_nome
 * @property string|null $situacao
 * @property string|null $situacao_detalhada
 * @property string|null $foto_url
 * @property Carbon|null $fonte_atualizada_em
 * @property-read Eleicao $eleicao
 * @property string|null $party_color Atribuído dinamicamente pelo PoliticalPanelController a partir de PartidoCor::colorMap() — não existe como coluna.
 */
class CandidatoPolitico extends Model
{
    protected $table = 'candidatos_politicos';

    protected $guarded = [];

    /** @return BelongsTo<Eleicao, $this> */
    public function eleicao(): BelongsTo
    {
        return $this->belongsTo(Eleicao::class);
    }

    /** @return BelongsTo<MunicipioEleitoral, $this> */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(MunicipioEleitoral::class, 'municipio_eleitoral_id');
    }

    /** @return HasMany<CandidatoFavorito, $this> */
    public function favoritos(): HasMany
    {
        return $this->hasMany(CandidatoFavorito::class, 'candidato_politico_id');
    }

    /** @return HasMany<NoticiaCandidato, $this> */
    public function noticias(): HasMany
    {
        return $this->hasMany(NoticiaCandidato::class, 'candidato_politico_id');
    }

    /** @return HasMany<VotacaoCandidatoMunicipio, $this> */
    public function votacoesMunicipais(): HasMany
    {
        return $this->hasMany(VotacaoCandidatoMunicipio::class, 'candidato_politico_id');
    }

    protected function casts(): array
    {
        return [
            'abrangencia' => CandidateScope::class,
            'fonte_atualizada_em' => 'datetime',
        ];
    }
}

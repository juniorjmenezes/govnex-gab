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
 * @property bool|null $avancou_segundo_turno Classificação para o 2º turno (ver SecondRoundClassifier): null enquanto a disputa não tem 2º turno decidido.
 * @property string|null $foto_url
 * @property Carbon|null $fonte_atualizada_em
 * @property-read Election $eleicao
 * @property string|null $party_color Atribuído dinamicamente pelo PoliticalPanelController a partir de PartyColor::colorMap() — não existe como coluna.
 * @property bool $is_holder Atribuído dinamicamente pelo PoliticalPanelController: é o titular do gabinete que está vendo a lista — não existe como coluna.
 * @property array{situacao: string, turno: int, votos: int, percentual: float|null, eleito: bool}|null $resultado Atribuído dinamicamente pelo PoliticalPanelController: resultado da eleição no turno mais recente — não existe como coluna.
 */
class Candidate extends Model
{
    protected $table = 'candidatos_politicos';

    protected $guarded = [];

    /** @return BelongsTo<Election, $this> */
    public function eleicao(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'eleicao_id');
    }

    /** @return BelongsTo<ElectoralMunicipality, $this> */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(ElectoralMunicipality::class, 'municipio_eleitoral_id');
    }

    /** @return HasMany<FavoriteCandidate, $this> */
    public function favoritos(): HasMany
    {
        return $this->hasMany(FavoriteCandidate::class, 'candidato_politico_id');
    }

    /** @return HasMany<CandidateNews, $this> */
    public function noticias(): HasMany
    {
        return $this->hasMany(CandidateNews::class, 'candidato_politico_id');
    }

    /** @return HasMany<CandidateMunicipalityVote, $this> */
    public function votacoesMunicipais(): HasMany
    {
        return $this->hasMany(CandidateMunicipalityVote::class, 'candidato_politico_id');
    }

    /**
     * Resultado por turno de presidente e governador (apuração do TSE).
     *
     * @return HasMany<CandidateElectionResult, $this>
     */
    public function resultados(): HasMany
    {
        return $this->hasMany(CandidateElectionResult::class, 'candidato_politico_id');
    }

    protected function casts(): array
    {
        return [
            'abrangencia' => CandidateScope::class,
            'fonte_atualizada_em' => 'datetime',
            'avancou_segundo_turno' => 'boolean',
        ];
    }
}

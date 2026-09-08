<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $eleicao_id
 * @property string $external_id
 * @property string $external_election_id
 * @property string|null $registro_tse Protocolo oficial de registro no TSE (ex.: CE075612026)
 * @property int $ano
 * @property string $uf
 * @property string|null $municipio
 * @property string $cargo
 * @property int $turno
 * @property string $cenario 'estimulado_1t' e variantes — nunca misturar cenários diferentes
 * @property int|null $cenario_id Id do cenário na fonte — único só dentro do mesmo registro/pesquisa, nunca global
 * @property string|null $cenario_nome Nome/descrição do cenário como publicado pela fonte
 * @property string|null $instituto
 * @property Carbon $publicada_em
 * @property Carbon|null $coleta_inicio_em
 * @property Carbon|null $coleta_fim_em
 * @property int|null $tamanho_amostra
 * @property float|null $margem_erro
 * @property float|null $nivel_confianca Nível de confiança estatístico da pesquisa (ex.: 95.0) — não confundir com $confianca
 * @property string|null $metodologia
 * @property string|null $abrangencia
 * @property string|null $tipo
 * @property string $fonte_url
 * @property Carbon|null $fonte_atualizada_em
 * @property int|null $confianca Confiança (0-100) da fonte atualmente persistida em resultados
 * @property string|null $origem_provider Provider que gerou os resultados atualmente persistidos
 * @property-read Collection<int, ResultadoPesquisaEleitoral> $resultados
 * @property-read Collection<int, PesquisaFonte> $fontes
 */
class PesquisaEleitoral extends Model
{
    protected $table = 'pesquisas_eleitorais';

    protected $guarded = [];

    /** @return BelongsTo<Eleicao, $this> */
    public function eleicao(): BelongsTo
    {
        return $this->belongsTo(Eleicao::class);
    }

    /** @return HasMany<ResultadoPesquisaEleitoral, $this> */
    public function resultados(): HasMany
    {
        return $this->hasMany(ResultadoPesquisaEleitoral::class, 'pesquisa_eleitoral_id');
    }

    /** @return HasMany<PesquisaFonte, $this> */
    public function fontes(): HasMany
    {
        return $this->hasMany(PesquisaFonte::class, 'pesquisa_eleitoral_id');
    }

    protected function casts(): array
    {
        return [
            'publicada_em' => 'date',
            'coleta_inicio_em' => 'date',
            'coleta_fim_em' => 'date',
            'margem_erro' => 'float',
            'nivel_confianca' => 'float',
            'fonte_atualizada_em' => 'datetime',
        ];
    }
}

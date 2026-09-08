<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Registro de proveniência: uma tentativa de coleta de resultados para uma
 * pesquisa eleitoral, bem-sucedida ou não, rastreável até a fonte exata.
 *
 * @property int $id
 * @property int|null $pesquisa_eleitoral_id
 * @property string $tipo 'oficial'|'pdf_oficial'|'portal'|'pollingdata'|'manual' — pode ter valores históricos 'electiolab' de antes da migração de fonte
 * @property string $provider Nome do provider que gerou o registro (ex.: "pollingdata")
 * @property string|null $url
 * @property string $status 'coletado'|'falhou'|'pendente'|'ignorado'
 * @property int $confidence_score
 * @property Carbon|null $coletado_em
 * @property string|null $hash_conteudo
 * @property string|null $erro
 * @property array<string, mixed>|null $metadata
 */
class PesquisaFonte extends Model
{
    protected $table = 'pesquisa_fontes';

    protected $guarded = [];

    /** @return BelongsTo<PesquisaEleitoral, $this> */
    public function pesquisa(): BelongsTo
    {
        return $this->belongsTo(PesquisaEleitoral::class, 'pesquisa_eleitoral_id');
    }

    protected function casts(): array
    {
        return [
            'coletado_em' => 'datetime',
            'metadata' => 'array',
        ];
    }
}

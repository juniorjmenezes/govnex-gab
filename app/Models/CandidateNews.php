<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Notícia associada a um candidato favorito de um gabinete. É por gabinete
 * porque os termos de busca extras são configurados por ele — o mesmo
 * candidato pode casar em um gabinete e não em outro.
 *
 * @property int $id
 * @property int $noticia_rss_id
 * @property int $candidato_politico_id
 * @property string|null $termo_casado
 * @property Carbon|null $created_at
 */
class CandidateNews extends TenantModel
{
    protected $table = 'noticias_candidatos';

    /** @return BelongsTo<RssNews, $this> */
    public function noticia(): BelongsTo
    {
        return $this->belongsTo(RssNews::class, 'noticia_rss_id');
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidato_politico_id');
    }
}

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
class NoticiaCandidato extends TenantModel
{
    protected $table = 'noticias_candidatos';

    /** @return BelongsTo<NoticiaRss, $this> */
    public function noticia(): BelongsTo
    {
        return $this->belongsTo(NoticiaRss::class, 'noticia_rss_id');
    }

    /** @return BelongsTo<CandidatoPolitico, $this> */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(CandidatoPolitico::class, 'candidato_politico_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Item coletado de um feed. Fica fora do escopo de gabinete de propósito:
 * a notícia é a mesma para todo mundo; o que é por gabinete é o casamento
 * com os candidatos favoritos (ver CandidateNews).
 *
 * @property int $id
 * @property int $fonte_rss_id
 * @property string $guid_hash
 * @property string $guid
 * @property string $titulo
 * @property string|null $resumo
 * @property string $url
 * @property string|null $imagem_url
 * @property Carbon|null $publicado_em
 */
class RssNews extends Model
{
    protected $table = 'noticias_rss';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['publicado_em' => 'datetime'];
    }

    /** @return BelongsTo<RssSource, $this> */
    public function fonte(): BelongsTo
    {
        return $this->belongsTo(RssSource::class, 'fonte_rss_id');
    }

    /** @return HasMany<CandidateNews, $this> */
    public function candidatos(): HasMany
    {
        return $this->hasMany(CandidateNews::class, 'noticia_rss_id');
    }
}

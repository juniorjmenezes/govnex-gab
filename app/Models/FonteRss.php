<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Portal de notícias cadastrado pelo root na administração. O catálogo é
 * único: a coleta acontece uma vez por fonte e as notícias servem a todos
 * os gabinetes, em vez de cada um baixar o mesmo feed.
 *
 * @property int $id
 * @property string $nome
 * @property string $url
 * @property bool $ativo
 * @property Carbon|null $ultima_coleta_em
 * @property string|null $etag
 * @property string|null $modificado_em
 * @property int $itens_importados
 * @property string|null $ultimo_erro
 * @property Carbon|null $ultimo_erro_em
 */
class FonteRss extends Model
{
    protected $table = 'fontes_rss';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'ultima_coleta_em' => 'datetime',
            'ultimo_erro_em' => 'datetime',
        ];
    }

    /** @return HasMany<NoticiaRss, $this> */
    public function noticias(): HasMany
    {
        return $this->hasMany(NoticiaRss::class);
    }
}

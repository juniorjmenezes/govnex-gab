<?php

namespace App\Services\News;

use App\Models\CandidatoFavorito;
use App\Models\NoticiaCandidato;
use App\Models\NoticiaRss;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Liga uma notícia aos candidatos favoritados. Casa pelo nome de urna e pelo
 * nome completo, mais os termos que o gabinete configurar no favorito — e
 * descarta quando algum termo de exclusão aparece, que é o jeito de cortar
 * homônimos ("Santos" o sobrenome vs. "Santos" a cidade).
 */
class CandidateNewsMatcher
{
    /** Nomes muito curtos casariam com qualquer coisa. */
    private const MIN_TERM_LENGTH = 4;

    public function matchNews(NoticiaRss $noticia): int
    {
        $favorites = $this->favorites();

        if ($favorites->isEmpty()) {
            return 0;
        }

        $haystack = $this->normalize($noticia->titulo.' '.($noticia->resumo ?? ''));
        $matches = 0;

        foreach ($favorites as $favorite) {
            $term = $this->firstMatchingTerm($favorite, $haystack);

            if ($term === null) {
                continue;
            }

            $this->link($noticia, $favorite, $term);

            $matches++;
        }

        return $matches;
    }

    /**
     * Reprocessa notícias já coletadas — útil quando o gabinete favorita um
     * candidato novo ou muda os termos, para não esperar a próxima coleta.
     */
    public function matchRecentForFavorite(CandidatoFavorito $favorite, int $limit = 200): int
    {
        $matches = 0;

        NoticiaRss::query()
            ->latest('publicado_em')
            ->limit($limit)
            ->get(['id', 'titulo', 'resumo'])
            ->each(function (NoticiaRss $noticia) use ($favorite, &$matches): void {
                $haystack = $this->normalize($noticia->titulo.' '.($noticia->resumo ?? ''));
                $term = $this->firstMatchingTerm($favorite, $haystack);

                if ($term === null) {
                    return;
                }

                $this->link($noticia, $favorite, $term);

                $matches++;
            });

        return $matches;
    }

    /**
     * gabinete_id é guardado contra atribuição em massa no TenantModel, e a
     * coleta roda fora de um contexto de gabinete — por isso o forceFill em
     * vez de firstOrCreate.
     */
    private function link(NoticiaRss $noticia, CandidatoFavorito $favorite, string $term): void
    {
        $exists = NoticiaCandidato::withoutGlobalScopes()
            ->where('noticia_rss_id', $noticia->id)
            ->where('gabinete_id', $favorite->gabinete_id)
            ->where('candidato_politico_id', $favorite->candidato_politico_id)
            ->exists();

        if ($exists) {
            return;
        }

        (new NoticiaCandidato)->forceFill([
            'noticia_rss_id' => $noticia->id,
            'gabinete_id' => $favorite->gabinete_id,
            'candidato_politico_id' => $favorite->candidato_politico_id,
            'termo_casado' => Str::limit($term, 160, ''),
        ])->save();
    }

    /** @return Collection<int, CandidatoFavorito> */
    private function favorites(): Collection
    {
        return CandidatoFavorito::withoutGlobalScopes()
            ->with('candidato:id,nome,nome_urna')
            ->get();
    }

    private function firstMatchingTerm(CandidatoFavorito $favorite, string $haystack): ?string
    {
        foreach ($this->excludeTerms($favorite) as $term) {
            if ($this->contains($haystack, $term)) {
                return null;
            }
        }

        foreach ($this->searchTerms($favorite) as $term) {
            if ($this->contains($haystack, $term)) {
                return $term;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function searchTerms(CandidatoFavorito $favorite): array
    {
        $terms = [
            $favorite->candidato?->nome_urna,
            $favorite->candidato?->nome,
            ...($favorite->termos_busca ?? []),
        ];

        return $this->sanitize($terms);
    }

    /** @return list<string> */
    private function excludeTerms(CandidatoFavorito $favorite): array
    {
        return $this->sanitize($favorite->termos_exclusao ?? []);
    }

    /**
     * @param  array<int, string|null>  $terms
     * @return list<string>
     */
    private function sanitize(array $terms): array
    {
        // `unique()` preserva as chaves originais; `array_values` é o que
        // devolve de fato a lista reindexada prometida no retorno.
        return array_values(
            collect($terms)
                ->filter(fn (?string $term): bool => is_string($term) && trim($term) !== '')
                ->map(fn (string $term): string => $this->normalize($term))
                ->filter(fn (string $term): bool => mb_strlen($term) >= self::MIN_TERM_LENGTH)
                ->unique()
                ->all(),
        );
    }

    /**
     * Exige limite de palavra nas pontas: sem isso "Ana" casaria dentro de
     * "Banana" e o painel encheria de notícia irrelevante.
     */
    private function contains(string $haystack, string $needle): bool
    {
        return (bool) preg_match(
            '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u',
            $haystack,
        );
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}

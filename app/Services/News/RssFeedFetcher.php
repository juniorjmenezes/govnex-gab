<?php

namespace App\Services\News;

use App\Models\FonteRss;
use App\Models\NoticiaRss;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

/**
 * Baixa e interpreta um feed. Suporta RSS 2.0 e Atom, que é o que os portais
 * de notícia usam na prática, e guarda ETag/Last-Modified para que a próxima
 * coleta receba 304 quando nada mudou.
 */
class RssFeedFetcher
{
    public function __construct(private readonly CandidateNewsMatcher $matcher) {}

    /**
     * @return int Quantidade de itens novos gravados.
     */
    public function fetch(FonteRss $fonte): int
    {
        $headers = ['User-Agent' => 'GOVNEX GAB (leitor de RSS)'];

        if ($fonte->etag) {
            $headers['If-None-Match'] = $fonte->etag;
        }

        if ($fonte->modificado_em) {
            $headers['If-Modified-Since'] = $fonte->modificado_em;
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(20)
                ->retry(2, 500, throw: false)
                ->get($fonte->url);
        } catch (Throwable $exception) {
            $this->registerFailure($fonte, $exception->getMessage());

            return 0;
        }

        if ($response->status() === 304) {
            $fonte->forceFill([
                'ultima_coleta_em' => now(),
                'ultimo_erro' => null,
                'ultimo_erro_em' => null,
            ])->save();

            return 0;
        }

        if (! $response->successful()) {
            $this->registerFailure($fonte, "HTTP {$response->status()}");

            return 0;
        }

        $items = $this->parse($response->body());

        if ($items === null) {
            $this->registerFailure($fonte, 'Conteúdo não é um feed RSS ou Atom válido.');

            return 0;
        }

        $created = $this->store($fonte, $items);

        $fonte->forceFill([
            'ultima_coleta_em' => now(),
            'etag' => $response->header('ETag') ?: null,
            'modificado_em' => $response->header('Last-Modified') ?: null,
            'itens_importados' => $fonte->itens_importados + $created,
            'ultimo_erro' => null,
            'ultimo_erro_em' => null,
        ])->save();

        return $created;
    }

    /**
     * @return list<array{guid: string, titulo: string, resumo: string|null, url: string, imagem_url: string|null, publicado_em: Carbon|null}>|null
     */
    public function parse(string $body): ?array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = new SimpleXMLElement($body, LIBXML_NOCDATA | LIBXML_NONET);
        } catch (Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $nodes = $xml->channel->item ?? $xml->entry ?? null;

        if ($nodes === null) {
            return null;
        }

        $items = [];

        foreach ($nodes as $node) {
            $url = $this->extractLink($node);
            $title = trim((string) ($node->title ?? ''));

            if ($url === null || $title === '') {
                continue;
            }

            $guid = trim((string) ($node->guid ?? $node->id ?? '')) ?: $url;

            $items[] = [
                'guid' => Str::limit($guid, 500, ''),
                'titulo' => Str::limit($title, 500, ''),
                'resumo' => $this->extractSummary($node),
                'url' => Str::limit($url, 1000, ''),
                'imagem_url' => $this->extractImage($node),
                'publicado_em' => $this->extractDate($node),
            ];
        }

        return $items;
    }

    /**
     * @param  list<array{guid: string, titulo: string, resumo: string|null, url: string, imagem_url: string|null, publicado_em: Carbon|null}>  $items
     */
    private function store(FonteRss $fonte, array $items): int
    {
        $created = 0;

        foreach ($items as $item) {
            $hash = hash('sha256', $item['guid']);

            $noticia = NoticiaRss::query()->firstOrNew([
                'fonte_rss_id' => $fonte->id,
                'guid_hash' => $hash,
            ]);

            $isNew = ! $noticia->exists;

            $noticia->fill([
                'guid' => $item['guid'],
                'titulo' => $item['titulo'],
                'resumo' => $item['resumo'],
                'url' => $item['url'],
                'imagem_url' => $item['imagem_url'],
                'publicado_em' => $item['publicado_em'],
            ])->save();

            if ($isNew) {
                $created++;
                $this->matcher->matchNews($noticia);
            }
        }

        return $created;
    }

    private function registerFailure(FonteRss $fonte, string $message): void
    {
        $fonte->forceFill([
            'ultima_coleta_em' => now(),
            'ultimo_erro' => Str::limit($message, 500),
            'ultimo_erro_em' => now(),
        ])->save();
    }

    private function extractLink(SimpleXMLElement $node): ?string
    {
        $link = trim((string) ($node->link ?? ''));

        if ($link !== '') {
            return $link;
        }

        // Atom traz o endereço em <link href="...">.
        foreach ($node->link ?? [] as $candidate) {
            $href = trim((string) ($candidate['href'] ?? ''));

            if ($href !== '') {
                return $href;
            }
        }

        return null;
    }

    private function extractSummary(SimpleXMLElement $node): ?string
    {
        $raw = (string) ($node->description ?? $node->summary ?? $node->content ?? '');
        $text = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : Str::limit($text, 1000);
    }

    private function extractImage(SimpleXMLElement $node): ?string
    {
        foreach (['enclosure', 'thumbnail', 'content'] as $tag) {
            foreach ($node->{$tag} ?? [] as $candidate) {
                $url = trim((string) ($candidate['url'] ?? ''));

                if ($url !== '') {
                    return Str::limit($url, 1000, '');
                }
            }
        }

        foreach ($node->children('media', true) as $child) {
            $url = trim((string) ($child['url'] ?? ''));

            if ($url !== '') {
                return Str::limit($url, 1000, '');
            }
        }

        return null;
    }

    private function extractDate(SimpleXMLElement $node): ?Carbon
    {
        $raw = trim((string) ($node->pubDate ?? $node->published ?? $node->updated ?? ''));

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }
}

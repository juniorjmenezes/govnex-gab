<?php

namespace Tests\Feature\News;

use App\Models\CandidatoFavorito;
use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\FonteRss;
use App\Models\Gabinete;
use App\Models\NoticiaCandidato;
use App\Models\NoticiaRss;
use App\Models\User;
use App\Services\News\RssFeedFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RssNewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_root_can_register_and_remove_a_source(): void
    {
        Http::fake();
        $root = User::factory()->root()->create();

        $this->actingAs($root)
            ->post(route('admin.rss-sources.store'), [
                'nome' => 'Portal Exemplo',
                'url' => 'https://exemplo.com.br/rss',
            ])
            ->assertRedirect(route('admin.rss-sources.index'));

        $this->assertDatabaseHas('fontes_rss', [
            'nome' => 'Portal Exemplo',
            'ativo' => true,
        ]);

        $fonte = FonteRss::query()->firstOrFail();

        $this->actingAs($root)
            ->get(route('admin.rss-sources.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/rss-sources/index')
                ->has('sources', 1));

        $this->actingAs($root)
            ->delete(route('admin.rss-sources.destroy', $fonte))
            ->assertRedirect(route('admin.rss-sources.index'));

        $this->assertDatabaseCount('fontes_rss', 0);
    }

    public function test_non_root_cannot_manage_sources(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->create(['gabinete_id' => $office->id]);

        $this->actingAs($user)
            ->get(route('admin.rss-sources.index'))
            ->assertForbidden();
    }

    public function test_duplicated_feed_is_rejected(): void
    {
        $root = User::factory()->root()->create();
        FonteRss::query()->create([
            'nome' => 'Portal Exemplo',
            'url' => 'https://exemplo.com.br/rss',
        ]);

        $this->actingAs($root)
            ->post(route('admin.rss-sources.store'), [
                'nome' => 'Outro nome',
                'url' => 'https://exemplo.com.br/rss',
            ])
            ->assertSessionHasErrors('url');
    }

    public function test_fetcher_imports_items_and_matches_favorite_candidate(): void
    {
        [$office, $candidate] = $this->favoriteCandidate('Zeca do Bairro');

        $fonte = FonteRss::query()->create([
            'nome' => 'Portal Exemplo',
            'url' => 'https://exemplo.com.br/rss',
        ]);

        Http::fake([
            'exemplo.com.br/*' => Http::response($this->feed([
                ['guid' => 'a1', 'title' => 'Zeca do Bairro apresenta projeto na Câmara'],
                ['guid' => 'a2', 'title' => 'Chuva alaga o centro da cidade'],
            ]), 200, ['ETag' => '"v1"']),
        ]);

        $created = app(RssFeedFetcher::class)->fetch($fonte->fresh());

        $this->assertSame(2, $created);
        $this->assertDatabaseCount('noticias_rss', 2);

        // Só a notícia que cita o candidato vira associação.
        $this->assertDatabaseCount('noticias_candidatos', 1);
        $this->assertDatabaseHas('noticias_candidatos', [
            'gabinete_id' => $office->id,
            'candidato_politico_id' => $candidate->id,
        ]);

        $this->assertSame('"v1"', $fonte->fresh()->etag);
    }

    public function test_second_collection_does_not_duplicate_items(): void
    {
        $this->favoriteCandidate('Zeca do Bairro');

        $fonte = FonteRss::query()->create([
            'nome' => 'Portal Exemplo',
            'url' => 'https://exemplo.com.br/rss',
        ]);

        Http::fake([
            'exemplo.com.br/*' => Http::response($this->feed([
                ['guid' => 'a1', 'title' => 'Zeca do Bairro apresenta projeto'],
            ])),
        ]);

        app(RssFeedFetcher::class)->fetch($fonte->fresh());
        app(RssFeedFetcher::class)->fetch($fonte->fresh());

        $this->assertDatabaseCount('noticias_rss', 1);
        $this->assertDatabaseCount('noticias_candidatos', 1);
    }

    public function test_exclusion_term_discards_homonym(): void
    {
        [, $candidate] = $this->favoriteCandidate('Santos');

        CandidatoFavorito::withoutGlobalScopes()
            ->where('candidato_politico_id', $candidate->id)
            ->update(['termos_exclusao' => json_encode(['santos futebol'])]);

        $fonte = FonteRss::query()->create([
            'nome' => 'Portal Exemplo',
            'url' => 'https://exemplo.com.br/rss',
        ]);

        Http::fake([
            'exemplo.com.br/*' => Http::response($this->feed([
                ['guid' => 'b1', 'title' => 'Santos Futebol Clube vence a partida'],
            ])),
        ]);

        app(RssFeedFetcher::class)->fetch($fonte->fresh());

        $this->assertDatabaseCount('noticias_rss', 1);
        $this->assertDatabaseCount('noticias_candidatos', 0);
    }

    public function test_http_failure_is_recorded_on_the_source(): void
    {
        $fonte = FonteRss::query()->create([
            'nome' => 'Portal Exemplo',
            'url' => 'https://exemplo.com.br/rss',
        ]);

        Http::fake(['exemplo.com.br/*' => Http::response('', 500)]);

        $created = app(RssFeedFetcher::class)->fetch($fonte->fresh());

        $this->assertSame(0, $created);
        $this->assertSame('HTTP 500', $fonte->fresh()->ultimo_erro);
    }

    public function test_not_modified_response_keeps_previous_items(): void
    {
        $fonte = FonteRss::query()->create([
            'nome' => 'Portal Exemplo',
            'url' => 'https://exemplo.com.br/rss',
            'etag' => '"v1"',
        ]);

        Http::fake(['exemplo.com.br/*' => Http::response('', 304)]);

        $this->assertSame(0, app(RssFeedFetcher::class)->fetch($fonte->fresh()));
        $this->assertDatabaseCount('noticias_rss', 0);
        $this->assertNull($fonte->fresh()->ultimo_erro);
        $this->assertNotNull($fonte->fresh()->ultima_coleta_em);
    }

    public function test_panel_only_shows_news_of_the_current_office(): void
    {
        [$office, $candidate] = $this->favoriteCandidate('Zeca do Bairro');
        $other = Gabinete::factory()->create();

        $fonte = FonteRss::query()->create([
            'nome' => 'Portal Exemplo',
            'url' => 'https://exemplo.com.br/rss',
        ]);
        $noticia = NoticiaRss::query()->create([
            'fonte_rss_id' => $fonte->id,
            'guid_hash' => hash('sha256', 'x1'),
            'guid' => 'x1',
            'titulo' => 'Notícia de outro gabinete',
            'url' => 'https://exemplo.com.br/x1',
        ]);
        (new NoticiaCandidato)->forceFill([
            'noticia_rss_id' => $noticia->id,
            'gabinete_id' => $other->id,
            'candidato_politico_id' => $candidate->id,
        ])->save();

        $user = User::factory()->create(['gabinete_id' => $office->id]);

        // O painel não carrega mais notícias; elas vêm do endpoint do modal,
        // que precisa respeitar o gabinete atual.
        $this->actingAs($user)
            ->getJson(route('politics.candidates.news', [
                'candidate' => $candidate,
                'eleicao_id' => $candidate->eleicao_id,
            ]))
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_news_endpoint_paginates_six_by_six(): void
    {
        [, $candidate] = $this->favoriteCandidate('Zeca do Bairro');

        $fonte = FonteRss::query()->create([
            'nome' => 'Portal Exemplo',
            'url' => 'https://exemplo.com.br/rss',
        ]);

        $items = [];
        for ($i = 1; $i <= 8; $i++) {
            $items[] = ['guid' => "n{$i}", 'title' => "Zeca do Bairro na notícia {$i}"];
        }

        Http::fake(['exemplo.com.br/*' => Http::response($this->feed($items))]);
        app(RssFeedFetcher::class)->fetch($fonte->fresh());

        $this->assertDatabaseCount('noticias_candidatos', 8);

        $office = CandidatoFavorito::withoutGlobalScopes()
            ->where('candidato_politico_id', $candidate->id)
            ->value('gabinete_id');
        $user = User::factory()->create(['gabinete_id' => $office]);

        $first = $this->actingAs($user)->getJson(route('politics.candidates.news', [
            'candidate' => $candidate,
            'eleicao_id' => $candidate->eleicao_id,
        ]))->assertOk();

        $first->assertJsonCount(6, 'data')
            ->assertJsonPath('total', 8)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('current_page', 1);

        $this->actingAs($user)->getJson(route('politics.candidates.news', [
            'candidate' => $candidate,
            'eleicao_id' => $candidate->eleicao_id,
            'page' => 2,
        ]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('current_page', 2);
    }

    /** @return array{0: Gabinete, 1: CandidatoPolitico} */
    private function favoriteCandidate(string $ballotName): array
    {
        $office = Gabinete::factory()->create();
        // A base de teste já pode trazer eleições semeadas: ano + tipo é único.
        $election = Eleicao::query()->firstOrCreate(
            ['ano' => 2024, 'tipo' => 'municipal'],
            ['nome' => 'Eleições 2024', 'primeiro_turno_em' => '2024-10-06'],
        );
        $candidate = CandidatoPolitico::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => '999999',
            'abrangencia' => 'nacional',
            'cargo' => 'VEREADOR',
            'nome' => mb_strtoupper($ballotName),
            'nome_urna' => $ballotName,
            'partido_sigla' => 'XYZ',
        ]);

        (new CandidatoFavorito)->forceFill([
            'gabinete_id' => $office->id,
            'candidato_politico_id' => $candidate->id,
        ])->save();

        return [$office, $candidate];
    }

    /** @param  list<array{guid: string, title: string}>  $items */
    private function feed(array $items): string
    {
        $entries = collect($items)
            ->map(fn (array $item): string => <<<XML
                <item>
                    <title>{$item['title']}</title>
                    <link>https://exemplo.com.br/{$item['guid']}</link>
                    <guid>{$item['guid']}</guid>
                    <description>Resumo da notícia.</description>
                    <pubDate>Wed, 03 Sep 2026 10:00:00 -0300</pubDate>
                </item>
                XML)
            ->implode("\n");

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0">
                <channel>
                    <title>Portal Exemplo</title>
                    {$entries}
                </channel>
            </rss>
            XML;
    }
}

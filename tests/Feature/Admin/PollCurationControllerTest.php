<?php

namespace Tests\Feature\Admin;

use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\PesquisaEleitoral;
use App\Models\PesquisaFonte;
use App\Models\ResultadoPesquisaEleitoral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PollCurationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_list_pesquisas_with_results_and_fontes(): void
    {
        $admin = User::factory()->root()->create();
        $this->existingElectioLabPesquisa();

        $this->actingAs($admin)
            ->get('/admin/pesquisas-eleitorais')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/polls/index')
                ->has('pesquisas.data', 1)
                ->has('pesquisas.data.0.resultados', 1)
                ->has('pesquisas.data.0.fontes', 1));
    }

    public function test_platform_admin_can_register_a_manual_poll_with_results(): void
    {
        $admin = User::factory()->root()->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();

        $response = $this->actingAs($admin)->post('/admin/pesquisas-eleitorais', [
            'eleicao_id' => $election->id,
            'cargo' => 'governador',
            'uf' => 'CE',
            'municipio' => null,
            'turno' => 1,
            'cenario' => 'estimulado_1t',
            'instituto' => 'AtlasIntel',
            'publicada_em' => today()->toDateString(),
            'fonte_url' => 'https://atlasintel.org/polls/exemplo',
            'provider' => 'AtlasIntel — PDF oficial',
            'confidence_score' => 90,
            'candidatos' => [
                ['nome' => 'Fulano', 'partido' => 'ABC', 'percentual' => 40.5, 'candidato_politico_id' => null],
                ['nome' => 'Ciclano', 'partido' => 'XYZ', 'percentual' => 35.2, 'candidato_politico_id' => null],
            ],
        ]);

        $response->assertRedirect('/admin/pesquisas-eleitorais');

        $pesquisa = PesquisaEleitoral::query()->where('instituto', 'AtlasIntel')->firstOrFail();
        $this->assertSame(90, $pesquisa->confianca);
        $this->assertSame('AtlasIntel — PDF oficial', $pesquisa->origem_provider);
        $this->assertSame(2, $pesquisa->resultados()->count());
        $this->assertDatabaseHas('resultados_pesquisas_eleitorais', [
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'candidato_nome' => 'Fulano',
            'percentual' => 40.5,
        ]);
        $this->assertDatabaseHas('pesquisa_fontes', [
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'tipo' => 'manual',
            'provider' => 'AtlasIntel — PDF oficial',
            'confidence_score' => 90,
        ]);
    }

    public function test_non_platform_admin_cannot_register_a_manual_poll(): void
    {
        $tenantUser = User::factory()->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();

        $this->actingAs($tenantUser)->post('/admin/pesquisas-eleitorais', [
            'eleicao_id' => $election->id,
            'cargo' => 'governador',
            'uf' => 'CE',
            'turno' => 1,
            'cenario' => 'estimulado_1t',
            'instituto' => 'AtlasIntel',
            'publicada_em' => today()->toDateString(),
            'fonte_url' => 'https://atlasintel.org/polls/exemplo',
            'provider' => 'AtlasIntel',
            'confidence_score' => 90,
            'candidatos' => [
                ['nome' => 'Fulano', 'partido' => null, 'percentual' => 40.5, 'candidato_politico_id' => null],
            ],
        ])->assertForbidden();

        $this->assertDatabaseCount('pesquisas_eleitorais', 0);
    }

    public function test_store_requires_at_least_one_candidate(): void
    {
        $admin = User::factory()->root()->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();

        $this->actingAs($admin)->post('/admin/pesquisas-eleitorais', [
            'eleicao_id' => $election->id,
            'cargo' => 'governador',
            'uf' => 'CE',
            'turno' => 1,
            'cenario' => 'estimulado_1t',
            'instituto' => 'AtlasIntel',
            'publicada_em' => today()->toDateString(),
            'fonte_url' => 'https://atlasintel.org/polls/exemplo',
            'provider' => 'AtlasIntel',
            'confidence_score' => 90,
            'candidatos' => [],
        ])->assertSessionHasErrors('candidatos');

        $this->assertDatabaseCount('pesquisas_eleitorais', 0);
    }

    public function test_updating_results_applies_when_confidence_is_high_enough(): void
    {
        $admin = User::factory()->root()->create();
        $pesquisa = $this->existingElectioLabPesquisa();

        $response = $this->actingAs($admin)->post("/admin/pesquisas-eleitorais/{$pesquisa->id}/resultados", [
            'provider' => 'AtlasIntel — PDF oficial',
            'confidence_score' => 90,
            'url' => null,
            'observacao' => 'Conferido no PDF original.',
            'candidatos' => [
                ['nome' => 'Corrigido', 'partido' => 'ABC', 'percentual' => 55.0, 'candidato_politico_id' => null],
            ],
        ]);

        $response->assertRedirect();
        $pesquisa->refresh();
        $this->assertSame(90, $pesquisa->confianca);
        $this->assertSame('AtlasIntel — PDF oficial', $pesquisa->origem_provider);
        $this->assertDatabaseHas('resultados_pesquisas_eleitorais', [
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'candidato_nome' => 'Corrigido',
            'percentual' => 55.0,
        ]);
        $this->assertSame(1, $pesquisa->resultados()->count());
    }

    public function test_updating_results_does_not_overwrite_a_higher_confidence_source(): void
    {
        $admin = User::factory()->root()->create();
        $pesquisa = $this->existingElectioLabPesquisa();
        $originalResult = $pesquisa->resultados()->first();

        $this->actingAs($admin)->post("/admin/pesquisas-eleitorais/{$pesquisa->id}/resultados", [
            'provider' => 'Boato não confirmado',
            'confidence_score' => 30,
            'candidatos' => [
                ['nome' => 'Boato', 'partido' => null, 'percentual' => 10.0, 'candidato_politico_id' => null],
            ],
        ]);

        $pesquisa->refresh();
        // Confiança e origem permanecem as do ElectioLab: a fonte fraca não aplicou.
        $this->assertSame(60, $pesquisa->confianca);
        $this->assertSame('electiolab', $pesquisa->origem_provider);
        $this->assertDatabaseHas('resultados_pesquisas_eleitorais', [
            'id' => $originalResult->id,
            'candidato_nome' => $originalResult->candidato_nome,
        ]);
        // Mas a tentativa fica registrada para auditoria.
        $this->assertDatabaseHas('pesquisa_fontes', [
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'provider' => 'Boato não confirmado',
            'confidence_score' => 30,
        ]);
    }

    public function test_platform_admin_can_delete_a_purely_manual_pesquisa(): void
    {
        $admin = User::factory()->root()->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $pesquisa = PesquisaEleitoral::query()->create($this->pesquisaAttributes($election));
        PesquisaFonte::query()->create([
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'tipo' => 'manual',
            'provider' => 'manual',
            'status' => 'coletado',
            'confidence_score' => 90,
            'coletado_em' => now(),
        ]);

        $this->actingAs($admin)
            ->delete("/admin/pesquisas-eleitorais/{$pesquisa->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('pesquisas_eleitorais', ['id' => $pesquisa->id]);
    }

    public function test_platform_admin_cannot_delete_a_pesquisa_with_an_automated_source(): void
    {
        $admin = User::factory()->root()->create();
        $pesquisa = $this->existingElectioLabPesquisa();

        $this->actingAs($admin)
            ->delete("/admin/pesquisas-eleitorais/{$pesquisa->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('pesquisas_eleitorais', ['id' => $pesquisa->id]);
    }

    public function test_candidates_endpoint_scopes_by_election_and_cargo(): void
    {
        $admin = User::factory()->root()->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $governor = CandidatoPolitico::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => '111',
            'abrangencia' => 'estadual',
            'uf' => 'CE',
            'cargo' => 'governador',
            'nome' => 'Fulano da Silva',
            'nome_urna' => 'Fulano',
            'numero' => '11',
            'partido_sigla' => 'ABC',
        ]);
        CandidatoPolitico::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => '222',
            'abrangencia' => 'estadual',
            'uf' => 'SP',
            'cargo' => 'governador',
            'nome' => 'Outro Estado',
            'nome_urna' => 'Outro',
            'numero' => '22',
            'partido_sigla' => 'XYZ',
        ]);

        $response = $this->actingAs($admin)->getJson(
            "/admin/pesquisas-eleitorais/candidatos?eleicao_id={$election->id}&cargo=governador&uf=CE",
        );

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['id' => $governor->id, 'name' => 'Fulano']);
    }

    private function existingElectioLabPesquisa(): PesquisaEleitoral
    {
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $pesquisa = PesquisaEleitoral::query()->create([
            ...$this->pesquisaAttributes($election),
            'confianca' => 60,
            'origem_provider' => 'electiolab',
        ]);
        ResultadoPesquisaEleitoral::query()->create([
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'external_candidate_id' => (string) Str::uuid(),
            'candidato_nome' => 'Original ElectioLab',
            'partido_sigla' => 'ABC',
            'percentual' => 45.0,
        ]);
        PesquisaFonte::query()->create([
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'tipo' => 'electiolab',
            'provider' => 'electiolab',
            'status' => 'coletado',
            'confidence_score' => 60,
            'coletado_em' => now(),
        ]);

        return $pesquisa;
    }

    /** @return array<string, mixed> */
    private function pesquisaAttributes(Eleicao $election): array
    {
        return [
            'eleicao_id' => $election->id,
            'external_id' => (string) Str::uuid(),
            'external_election_id' => (string) Str::uuid(),
            'ano' => $election->ano,
            'uf' => 'CE',
            'cargo' => 'governador',
            'turno' => 1,
            'instituto' => 'Instituto Teste',
            'publicada_em' => today(),
            'fonte_url' => 'https://exemplo.test',
        ];
    }
}

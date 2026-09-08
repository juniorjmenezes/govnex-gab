<?php

namespace Tests\Feature;

use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\Gabinete;
use App\Models\LocalVotacaoEleitoral;
use App\Models\MunicipioEleitoral;
use App\Models\SecaoEleitoral;
use App\Models\User;
use App\Models\VotoSecaoCandidato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ElectoralHeatmapTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_empty_state_when_electoral_number_is_missing(): void
    {
        $office = Gabinete::factory()->create(['numero_eleitoral' => null]);
        $user = User::factory()->advisor()->forGabinete($office)->create();

        $this->actingAs($user)
            ->get(route('voters.map'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('voters/electoral-map')
                ->where('summary.configured', false)
                ->where('summary.reason', 'number_missing')
                ->where('points', []));
    }

    public function test_shows_unmatched_state_when_electoral_number_has_no_tse_candidate(): void
    {
        $office = Gabinete::factory()->create(['numero_eleitoral' => '99999']);
        $user = User::factory()->advisor()->forGabinete($office)->create();

        $this->actingAs($user)
            ->get(route('voters.map'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('voters/electoral-map')
                ->where('summary.configured', false)
                ->where('summary.reason', 'candidate_unmatched')
                ->where('points', []));
    }

    public function test_aggregates_votes_by_polling_location_isolated_per_office(): void
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890',
            'codigo_ibge' => '2304251',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $election = Eleicao::query()
            ->where('ano', 2024)
            ->where('tipo', 'municipal')
            ->firstOrFail();
        $titular = CandidatoPolitico::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => '60001945113',
            'abrangencia' => 'municipal',
            'municipio_eleitoral_id' => $municipality->id,
            'uf' => 'CE',
            'cargo' => 'Vereador',
            'nome' => 'MARCOS JOSE SILVEIRA',
            'nome_urna' => 'MARCOS SILVEIRA',
            'numero' => '11555',
            'partido_sigla' => 'PP',
        ]);
        $office = Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
            'numero_eleitoral' => '11555',
            'candidato_titular_id' => $titular->id,
        ]);
        $user = User::factory()->advisor()->forGabinete($office)->create();

        $location = LocalVotacaoEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'municipio_eleitoral_id' => $municipality->id,
            'nr_zona' => '30',
            'nr_local_votacao' => '0001',
            'nome' => 'ESCOLA MUNICIPAL',
            'endereco' => 'RUA A, 10',
            'latitude' => -3.7358662,
            'longitude' => -38.5170603,
            'latitude_fonte' => 'tse',
            'fonte_url' => 'https://cdn.tse.jus.br/eleitorado_local_votacao_2024.zip',
        ]);
        $pendingLocation = LocalVotacaoEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'municipio_eleitoral_id' => $municipality->id,
            'nr_zona' => '31',
            'nr_local_votacao' => '0002',
            'nome' => 'IGREJA SAO JOSE',
            'fonte_url' => 'https://cdn.tse.jus.br/eleitorado_local_votacao_2024.zip',
        ]);

        $sectionOne = SecaoEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'municipio_eleitoral_id' => $municipality->id,
            'local_votacao_eleitoral_id' => $location->id,
            'nr_zona' => '30',
            'nr_secao' => '0011',
        ]);
        $sectionTwo = SecaoEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'municipio_eleitoral_id' => $municipality->id,
            'local_votacao_eleitoral_id' => $location->id,
            'nr_zona' => '30',
            'nr_secao' => '0012',
        ]);
        $pendingSection = SecaoEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'municipio_eleitoral_id' => $municipality->id,
            'local_votacao_eleitoral_id' => $pendingLocation->id,
            'nr_zona' => '31',
            'nr_secao' => '0020',
        ]);

        VotoSecaoCandidato::query()->create([
            'secao_eleitoral_id' => $sectionOne->id,
            'candidato_politico_id' => $titular->id,
            'votos' => 500,
            'fonte_url' => 'https://cdn.tse.jus.br/votacao_secao_2024_CE.zip',
        ]);
        VotoSecaoCandidato::query()->create([
            'secao_eleitoral_id' => $sectionTwo->id,
            'candidato_politico_id' => $titular->id,
            'votos' => 276,
            'fonte_url' => 'https://cdn.tse.jus.br/votacao_secao_2024_CE.zip',
        ]);
        VotoSecaoCandidato::query()->create([
            'secao_eleitoral_id' => $pendingSection->id,
            'candidato_politico_id' => $titular->id,
            'votos' => 100,
            'fonte_url' => 'https://cdn.tse.jus.br/votacao_secao_2024_CE.zip',
        ]);

        $this->actingAs($user)
            ->get(route('voters.map'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('voters/electoral-map')
                ->where('summary.configured', true)
                ->where('summary.candidate.name', 'MARCOS SILVEIRA')
                ->where('summary.totalVotes', 876)
                ->where('summary.totalLocations', 2)
                ->where('summary.locatedLocations', 1)
                ->where('summary.pendingGeocoding', 1)
                ->has('points', 1)
                ->where('points.0.id', $location->id)
                ->where('points.0.votes', 776)
                ->where('points.0.sections', 2));
    }

    public function test_platform_administrator_cannot_access_electoral_map(): void
    {
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->get(route('voters.map'))
            ->assertForbidden();
    }
}

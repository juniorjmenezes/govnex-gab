<?php

namespace Tests\Feature;

use App\Enums\CandidateScope;
use App\Models\CandidatoPolitico;
use App\Models\Cidadao;
use App\Models\ComparecimentoEleitoralMunicipio;
use App\Models\Eleicao;
use App\Models\EleitoradoMunicipioSnapshot;
use App\Models\Gabinete;
use App\Models\MediaPesquisaEleitoral;
use App\Models\MunicipioEleitoral;
use App\Models\PartidoCor;
use App\Models\PesquisaEleitoral;
use App\Models\ResultadoPesquisaEleitoral;
use App\Models\SincronizacaoTse;
use App\Models\User;
use App\Models\VotacaoCandidatoMunicipio;
use App\Services\Politics\OfficeHolderCandidateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PoliticalPanelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Eleição municipal já realizada: o painel troca a pesquisa de intenção
     * de voto pela apuração — depois do resultado, é ele que importa.
     */
    public function test_selecting_a_past_municipal_election_shows_its_result_instead_of_polls(): void
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $election = Eleicao::query()->where('ano', 2024)->firstOrFail();
        $holder = $this->municipalCandidate($election, $municipality, '11555', 'MARCOS', 'PP');
        $winner = $this->municipalCandidate($election, $municipality, '22222', 'ANA', 'PT');
        $loser = $this->municipalCandidate($election, $municipality, '33333', 'JOAO', 'PT');
        $office = Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
            'numero_eleitoral' => '11555',
            'candidato_titular_id' => $holder->id,
        ]);
        $user = User::factory()->advisor()->forGabinete($office)->create();
        ComparecimentoEleitoralMunicipio::query()->create([
            'municipio_eleitoral_id' => $municipality->id,
            'eleicao_id' => $election->id,
            'codigo_eleicao_tse' => '619',
            'ano' => 2024,
            'turno' => 1,
            'data_eleicao' => '2024-10-06',
            'eleitores_aptos' => 1_000,
            'comparecimento' => 800,
            'abstencoes' => 200,
            'fonte_url' => 'https://dadosabertos.tse.jus.br/',
        ]);
        PartidoCor::query()->create(['sigla' => 'PT', 'cor' => '#FF0000']);
        $mayor = $this->municipalCandidate($election, $municipality, '15', 'MARIA', 'MDB', 'Prefeito');
        $defeatedMayor = $this->municipalCandidate($election, $municipality, '45', 'CARLOS', 'PSDB', 'Prefeito');
        $this->municipalVote($election, $municipality, $winner, 1_200, true);
        $this->municipalVote($election, $municipality, $holder, 776, true);
        $this->municipalVote($election, $municipality, $loser, 100, false);
        $this->municipalVote($election, $municipality, $mayor, 4_200, true);
        $this->municipalVote($election, $municipality, $defeatedMayor, 3_100, false);

        $geral = Eleicao::query()->where('ano', 2026)->firstOrFail();

        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('politics/index')
                ->where('municipalElection.election.year', 2024)
                ->where('municipalElection.candidates', 3)
                ->where('municipalElection.seats', 2)
                ->where('municipalElection.turnout.eligible', 1_000)
                ->where('municipalElection.turnout.voted', 800)
                ->where('municipalElection.turnout.percentage', 80)
                ->where('municipalElection.holder.votes', 776)
                ->where('municipalElection.holder.position', 2)
                ->where('municipalElection.holder.elected', true)
                ->has('municipalElection.elected', 2)
                ->where('municipalElection.elected.0.name', 'ANA')
                ->where('municipalElection.elected.0.is_holder', false)
                ->where('municipalElection.elected.1.name', 'MARCOS')
                ->where('municipalElection.elected.1.is_holder', true)
                ->where('municipalElection.parties.0.party', 'PT')
                ->where('municipalElection.parties.0.seats', 1)
                // A cadeira e a linha do candidato levam a cor cadastrada.
                ->where('municipalElection.parties.0.color', '#FF0000')
                ->where('municipalElection.elected.0.party_color', '#FF0000')
                ->where('municipalElection.runners_up.0.party_color', '#FF0000')
                // Partido sem cor cadastrada não inventa uma.
                ->where('municipalElection.mayor.candidates.0.party_color', null)
                // Prefeito é disputa à parte: entra com eleito e derrotado, e
                // não polui a contagem da proporcional.
                ->has('municipalElection.mayor.candidates', 2)
                ->where('municipalElection.mayor.candidates.0.name', 'MARIA')
                ->where('municipalElection.mayor.candidates.0.elected', true)
                ->where('municipalElection.mayor.candidates.1.name', 'CARLOS')
                ->where('municipalElection.mayor.candidates.1.elected', false)
                ->has('municipalElection.runners_up', 1)
                ->where('municipalElection.runners_up.0.name', 'JOAO')
                // Pesquisa de eleição apurada sai do painel.
                ->where('polls', null));

        // Eleição que ainda vai acontecer segue no comportamento de sempre:
        // pesquisas à vista, sem apuração.
        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $geral->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('politics/index')
                ->where('municipalElection', null)
                ->has('polls'));
    }

    /** Sem votação nominal daquele município, não há apuração a mostrar. */
    public function test_the_municipal_result_is_absent_without_nominal_votes(): void
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $office = Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
        ]);
        $user = User::factory()->advisor()->forGabinete($office)->create();

        $election = Eleicao::query()->where('ano', 2024)->firstOrFail();

        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('politics/index')
                ->where('municipalElection', null));
    }

    private function municipalCandidate(
        Eleicao $election,
        MunicipioEleitoral $municipality,
        string $number,
        string $ballotName,
        string $party,
        string $cargo = 'Vereador',
    ): CandidatoPolitico {
        return CandidatoPolitico::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => "6000{$number}",
            'abrangencia' => CandidateScope::Municipal->value,
            'municipio_eleitoral_id' => $municipality->id,
            'uf' => 'CE',
            'cargo' => $cargo,
            'nome' => "Nome {$number}",
            'nome_urna' => $ballotName,
            'numero' => $number,
            'partido_sigla' => $party,
        ]);
    }

    private function municipalVote(
        Eleicao $election,
        MunicipioEleitoral $municipality,
        CandidatoPolitico $candidate,
        int $votes,
        bool $elected,
    ): VotacaoCandidatoMunicipio {
        return VotacaoCandidatoMunicipio::query()->create([
            'candidato_politico_id' => $candidate->id,
            'municipio_eleitoral_id' => $municipality->id,
            'eleicao_id' => $election->id,
            'codigo_eleicao_tse' => '619',
            'ano' => 2024,
            'turno' => 1,
            'data_eleicao' => '2024-10-06',
            'votos_nominais' => $votes,
            'votos_nominais_validos' => $votes,
            'situacao_totalizacao' => $elected ? 'ELEITO' : 'NÃO ELEITO',
            'eleito' => $elected,
            'fonte_url' => 'https://dadosabertos.tse.jus.br/',
        ]);
    }

    public function test_panel_separates_official_electorate_from_internal_voters(): void
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '13692',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $office = Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
        ]);
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->advisor()->forGabinete($office)->create();
        Cidadao::factory()->count(2)->forGabinete($office)->create(['eleitor' => true]);
        Cidadao::factory()->forGabinete($office)->create(['eleitor' => false]);
        Cidadao::factory()->forGabinete($otherOffice)->create(['eleitor' => true]);
        EleitoradoMunicipioSnapshot::query()->create([
            'municipio_eleitoral_id' => $municipality->id,
            'ano_referencia' => 2026,
            'data_referencia' => '2026-07-01',
            'eleitores_aptos' => 20_000,
            'fonte_url' => 'https://dadosabertos.tse.jus.br/',
        ]);
        $lastElection = Eleicao::query()->where('ano', 2024)->firstOrFail();
        ComparecimentoEleitoralMunicipio::query()->create([
            'municipio_eleitoral_id' => $municipality->id,
            'eleicao_id' => $lastElection->id,
            'codigo_eleicao_tse' => '619',
            'ano' => 2024,
            'turno' => 1,
            'data_eleicao' => '2024-10-06',
            'eleitores_aptos' => 20_000,
            'comparecimento' => 17_000,
            'abstencoes' => 3_000,
            'fonte_url' => 'https://dadosabertos.tse.jus.br/',
        ]);
        $holder = $this->candidate(
            $lastElection,
            '11555',
            CandidateScope::Municipal,
            'CE',
            $municipality->id,
            'Vereador',
        );
        $office->forceFill([
            'numero_eleitoral' => '11555',
            'candidato_titular_id' => $holder->id,
        ])->save();
        VotacaoCandidatoMunicipio::query()->create([
            'candidato_politico_id' => $holder->id,
            'municipio_eleitoral_id' => $municipality->id,
            'eleicao_id' => $lastElection->id,
            'codigo_eleicao_tse' => '619',
            'ano' => 2024,
            'turno' => 1,
            'data_eleicao' => '2024-10-06',
            'votos_nominais' => 776,
            'votos_nominais_validos' => 776,
            'situacao_totalizacao' => 'ELEITO POR MÉDIA',
            'eleito' => true,
            'fonte_url' => 'https://dadosabertos.tse.jus.br/',
        ]);

        $this->actingAs($user)
            ->get(route('politics.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('politics/index')
                ->where('stats.official_eligible', 20_000)
                ->where('stats.last_turnout', 17_000)
                ->where('stats.last_turnout_eligible', 20_000)
                ->where('stats.last_turnout_percentage', 85)
                ->where('stats.last_turnout_abstentions', 3_000)
                ->where('stats.last_election_name', 'Eleições Municipais 2024')
                ->where('stats.last_election_date', '2024-10-06')
                ->where('stats.last_election_round', 1)
                ->where('stats.holder_configured_number', '11555')
                ->where('stats.holder_candidate_name', 'Nome 11555')
                ->where('stats.holder_candidate_party', 'ABC')
                ->where('stats.holder_votes', 776)
                ->where('stats.holder_result_status', 'ELEITO POR MÉDIA')
                ->where('stats.holder_elected', true)
                ->where('stats.holder_election_name', 'Eleições Municipais 2024')
                ->where('stats.internal_voters', 2)
                ->where('stats.coverage_percentage', 0.01)
                ->where('municipality.mapped', true)
                ->where('municipality.tse_code', '13692')
                ->where('canFavorite', false));
    }

    public function test_panel_surfaces_global_tse_sync_status_for_electorate_and_candidates(): void
    {
        // electorate/candidates/turnout/candidate_votes são datasets globais
        // do TSE — cobrem o Brasil inteiro numa única sincronização, sempre
        // gravada com gabinete_id nulo (ver GlobalPoliticalDataSyncController).
        // O indicador do rodapé precisa achar essa sincronização mesmo sem
        // nenhum registro vinculado ao gabinete que está olhando o painel.
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->advisor()->forGabinete($office)->create();
        SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'electorate',
            'ano' => 2026,
            'fonte_url' => 'https://dadosabertos.tse.jus.br/',
            'situacao' => 'concluida',
            'registros_processados' => 150_000_000,
            'iniciada_em' => now()->subHour(),
            'concluida_em' => now(),
        ]);
        SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'candidates',
            'ano' => 2026,
            'fonte_url' => 'https://dadosabertos.tse.jus.br/',
            'situacao' => 'processando',
            'registros_processados' => 500,
            'iniciada_em' => now(),
        ]);
        // Sincronização de outro gabinete no mesmo dataset não deve ser
        // confundida com a global.
        $otherOffice = Gabinete::factory()->create();
        SincronizacaoTse::query()->create([
            'gabinete_id' => $otherOffice->id,
            'dataset' => 'electorate',
            'ano' => 2026,
            'fonte_url' => 'https://dadosabertos.tse.jus.br/',
            'situacao' => 'falhou',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('politics.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sync.electorate.status', 'concluida')
                ->where('sync.electorate.processed', 150_000_000)
                ->where('sync.candidates.status', 'processando'));
    }

    public function test_panel_lists_only_candidates_compatible_with_office_territory(): void
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '13692',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $otherMunicipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '13897',
            'nome' => 'Sobral',
            'uf' => 'CE',
        ]);
        $office = Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
        ]);
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();

        $visible = [
            $this->candidate($election, '1', CandidateScope::National, null, null, 'Presidente'),
            $this->candidate($election, '2', CandidateScope::State, 'CE', null, 'Governador'),
            $this->candidate($election, '3', CandidateScope::Municipal, 'CE', $municipality->id, 'Vereador'),
        ];
        $this->candidate($election, '4', CandidateScope::State, 'SP', null, 'Governador');
        $this->candidate($election, '5', CandidateScope::Municipal, 'CE', $otherMunicipality->id, 'Vereador');

        $response = $this->actingAs($user)->get(route('politics.index', [
            'eleicao_id' => $election->id,
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('politics/index')
            ->has('candidates.data', 3)
            ->where('candidates.data.0.id', $visible[1]->id)
            ->where('candidates.data.1.id', $visible[0]->id)
            ->where('candidates.data.2.id', $visible[2]->id));
    }

    /**
     * O titular é o candidato que o gabinete acompanha por definição: entra
     * nos favoritos sozinho, assim que é resolvido, sem depender de alguém
     * marcar.
     */
    public function test_resolving_a_titular_favorites_it_for_the_office(): void
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $election = Eleicao::query()->where('ano', 2024)->firstOrFail();
        $titular = $this->municipalCandidate($election, $municipality, '11555', 'MARCOS', 'PP');
        $office = Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
            'numero_eleitoral' => '11555',
            'candidato_titular_id' => null,
        ]);

        app(OfficeHolderCandidateResolver::class)->resolveOffice($office);

        $this->assertSame($titular->id, $office->fresh()->candidato_titular_id);
        $this->assertDatabaseHas('candidatos_favoritos', [
            'gabinete_id' => $office->id,
            'candidato_politico_id' => $titular->id,
            'escolhido_por_id' => null,
        ]);
    }

    /** Desmarcar o titular desligaria notícias e destaque do próprio gabinete. */
    public function test_the_titular_cannot_be_removed_from_favorites(): void
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $election = Eleicao::query()->where('ano', 2024)->firstOrFail();
        $titular = $this->municipalCandidate($election, $municipality, '11555', 'MARCOS', 'PP');
        $office = Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
            'numero_eleitoral' => '11555',
            'candidato_titular_id' => $titular->id,
        ]);
        $councilor = User::factory()->councilor()->forGabinete($office)->create();
        $titular->favoritos()->forceCreate([
            'gabinete_id' => $office->id,
            'escolhido_por_id' => null,
        ]);

        $this->actingAs($councilor)
            ->delete(route('politics.favorites.destroy', $titular))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', [
                'type' => 'error',
                'message' => 'O titular do gabinete fica sempre nos favoritos.',
            ]);

        $this->assertDatabaseHas('candidatos_favoritos', [
            'gabinete_id' => $office->id,
            'candidato_politico_id' => $titular->id,
        ]);
    }

    public function test_panel_lists_favorited_candidates_first(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $councilor = User::factory()->councilor()->forGabinete($office)->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();

        $alphabeticallyFirst = $this->candidate($election, '1', CandidateScope::State, 'CE', null, 'Governador');
        $alphabeticallySecond = $this->candidate($election, '2', CandidateScope::State, 'CE', null, 'Governador');
        $alphabeticallySecond->favoritos()->forceCreate([
            'gabinete_id' => $office->id,
            'escolhido_por_id' => $councilor->id,
        ]);

        // Sem favorito, a ordem seria nome_urna alfabética (Nome 1 antes de
        // Nome 2) — favoritar o segundo precisa trazê-lo pro topo mesmo
        // assim.
        $this->actingAs($councilor)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.data.0.id', $alphabeticallySecond->id)
                ->where('candidates.data.0.is_favorite', true)
                ->where('candidates.data.1.id', $alphabeticallyFirst->id)
                ->where('candidates.data.1.is_favorite', false));
    }

    public function test_candidate_party_color_matches_regardless_of_accent(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->advisor()->forGabinete($office)->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        PartidoCor::query()->create(['sigla' => 'MISSÃO', 'cor' => '#FFD600']);

        // O TSE publica a sigla sem acento ("MISSAO") em alguns datasets —
        // a cor cadastrada com acento ("MISSÃO") precisa valer do mesmo jeito.
        $candidate = CandidatoPolitico::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => '1',
            'abrangencia' => CandidateScope::State,
            'uf' => 'CE',
            'cargo' => 'Governador',
            'nome' => 'Candidato Missão',
            'nome_urna' => 'Missionário',
            'partido_sigla' => 'MISSAO',
        ]);

        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.data.0.id', $candidate->id)
                ->where('candidates.data.0.party_color', '#FFD600'));
    }

    public function test_only_councilor_can_manage_private_office_favorites(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $otherOffice = Gabinete::factory()->create(['estado' => 'CE']);
        $councilor = User::factory()->councilor()->forGabinete($office)->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();
        $otherCouncilor = User::factory()->councilor()->forGabinete($otherOffice)->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $candidate = $this->candidate(
            $election,
            '10',
            CandidateScope::State,
            'CE',
            null,
            'Governador',
        );

        $this->actingAs($advisor)
            ->post(route('politics.favorites.store', $candidate))
            ->assertForbidden();

        $this->actingAs($councilor)
            ->post(route('politics.favorites.store', $candidate))
            ->assertRedirect();

        $this->assertDatabaseHas('candidatos_favoritos', [
            'gabinete_id' => $office->id,
            'candidato_politico_id' => $candidate->id,
            'escolhido_por_id' => $councilor->id,
        ]);

        $this->actingAs($otherCouncilor)
            ->get(route('politics.index', [
                'eleicao_id' => $election->id,
                'favoritos' => 1,
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 0)
                ->where('stats.favorites', 0));

        $this->actingAs($councilor)
            ->delete(route('politics.favorites.destroy', $candidate))
            ->assertRedirect();

        $this->assertDatabaseMissing('candidatos_favoritos', [
            'gabinete_id' => $office->id,
            'candidato_politico_id' => $candidate->id,
        ]);
    }

    public function test_councilor_cannot_favorite_candidate_outside_office_territory(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $councilor = User::factory()->councilor()->forGabinete($office)->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $candidate = $this->candidate(
            $election,
            '20',
            CandidateScope::State,
            'SP',
            null,
            'Governador',
        );

        $this->actingAs($councilor)
            ->post(route('politics.favorites.store', $candidate))
            ->assertNotFound();
    }

    public function test_panel_exposes_state_polls_and_highlights_favorite_candidates(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $councilor = User::factory()->councilor()->forGabinete($office)->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $candidate = $this->candidate(
            $election,
            '30',
            CandidateScope::State,
            'CE',
            null,
            'Governador',
        );
        $candidate->favoritos()->forceCreate([
            'gabinete_id' => $office->id,
            'escolhido_por_id' => $councilor->id,
        ]);
        $poll = PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'external_election_id' => '11111111-1111-4111-8111-111111111111',
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'governador',
            'turno' => 1,
            'instituto' => 'Instituto Teste',
            'publicada_em' => '2026-07-20',
            'tamanho_amostra' => 1200,
            'margem_erro' => 2.5,
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
            'fonte_atualizada_em' => now(),
        ]);
        ResultadoPesquisaEleitoral::query()->create([
            'pesquisa_eleitoral_id' => $poll->id,
            'external_candidate_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'candidato_politico_id' => $candidate->id,
            'candidato_nome' => $candidate->nome_urna,
            'partido_sigla' => 'ABC',
            'percentual' => 42.5,
        ]);
        MediaPesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'external_election_id' => '11111111-1111-4111-8111-111111111111',
            'external_candidate_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'candidato_politico_id' => $candidate->id,
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'governador',
            'candidato_nome' => $candidate->nome_urna,
            'partido_sigla' => 'ABC',
            'media_ponderada' => 41.2,
            'pesquisas_incluidas' => 3,
            'amostra_total' => 3600,
            'fonte_url' => 'https://electiolab.test/api/v1/averages',
        ]);
        PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'external_election_id' => '11111111-1111-4111-8111-111111111111',
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'governador',
            'turno' => 1,
            'instituto' => 'Instituto Futuro',
            'publicada_em' => today()->addMonth(),
            'coleta_inicio_em' => today()->addMonth()->subDays(2),
            'coleta_fim_em' => today()->addMonth(),
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
        ]);

        $this->actingAs($councilor)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('polls.state', 'CE')
                ->where('polls.available', true)
                ->where('polls.offices.0.slug', 'presidente')
                ->where('polls.offices.0.scope_label', 'Brasil')
                ->where('polls.offices.1.slug', 'governador')
                ->where('polls.offices.1.scope_label', 'CE')
                ->where('polls.offices.1.has_aggregate', true)
                ->has('polls.offices.1.polls', 1)
                ->where('polls.offices.1.polls.0.institute', 'Instituto Teste')
                ->where('polls.offices.1.polls.0.results.0.is_favorite', true)
                ->where('polls.offices.1.averages.0.source', 'electiolab')
                ->where('polls.offices.1.averages.0.percentage', 41.2));
    }

    public function test_panel_falls_back_to_all_averages_when_the_latest_poll_has_no_candidate_breakdown(): void
    {
        // Reproduz o comportamento real do ElectioLab para presidente: a
        // pesquisa individual não vem com detalhamento por candidato (só a
        // média consolidada em /averages). Sem o fallback, o filtro
        // whereIn(external_candidate_id, []) zera médias reais e já
        // calculadas.
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $councilor = User::factory()->councilor()->forGabinete($office)->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $candidate = $this->candidate(
            $election,
            '13',
            CandidateScope::National,
            null,
            null,
            'Presidente',
        );

        PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'external_election_id' => '22222222-2222-4222-8222-222222222222',
            'ano' => 2026,
            'uf' => 'BR',
            'cargo' => 'presidente',
            'turno' => 1,
            'instituto' => 'Instituto Nacional',
            'publicada_em' => '2026-07-20',
            'tamanho_amostra' => 2000,
            'margem_erro' => 2.0,
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
            'fonte_atualizada_em' => now(),
        ]);
        MediaPesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'external_election_id' => '22222222-2222-4222-8222-222222222222',
            'external_candidate_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'candidato_politico_id' => $candidate->id,
            'ano' => 2026,
            'uf' => 'BR',
            'cargo' => 'presidente',
            'candidato_nome' => $candidate->nome_urna,
            'partido_sigla' => 'ABC',
            'media_ponderada' => 40.5,
            'pesquisas_incluidas' => 5,
            'amostra_total' => 10000,
            'fonte_url' => 'https://electiolab.test/api/v1/averages',
        ]);

        $this->actingAs($councilor)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('polls.offices.0.slug', 'presidente')
                ->has('polls.offices.0.polls', 1)
                ->where('polls.offices.0.polls.0.results', [])
                ->where('polls.offices.0.has_aggregate', true)
                ->where('polls.offices.0.averages.0.source', 'electiolab')
                ->where('polls.offices.0.averages.0.percentage', 40.5));
    }

    public function test_panel_prefers_its_own_weighted_average_over_electiolabs_when_it_covers_more_than_one_poll(): void
    {
        // Duas pesquisas individuais recentes com o mesmo candidato já
        // detalhado bastam para o MediaCalculator produzir uma agregação
        // própria — nesse caso o painel deve preferi-la à /averages do
        // ElectioLab, mesmo que esta última também esteja disponível.
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $councilor = User::factory()->councilor()->forGabinete($office)->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $candidate = $this->candidate(
            $election,
            '31',
            CandidateScope::State,
            'CE',
            null,
            'Governador',
        );

        foreach ([['1111', 5, 40.0], ['2222', 10, 43.0]] as [$suffix, $daysAgo, $percentual]) {
            $poll = PesquisaEleitoral::query()->create([
                'eleicao_id' => $election->id,
                'external_id' => "poll-own-{$suffix}",
                'external_election_id' => 'election-own',
                'ano' => 2026,
                'uf' => 'CE',
                'cargo' => 'governador',
                'turno' => 1,
                'instituto' => "Instituto {$suffix}",
                'publicada_em' => today()->subDays($daysAgo),
                'tamanho_amostra' => 1000,
                'fonte_url' => 'https://electiolab.test/api/v1/polls',
            ]);
            ResultadoPesquisaEleitoral::query()->create([
                'pesquisa_eleitoral_id' => $poll->id,
                'external_candidate_id' => 'candidate-own',
                'candidato_politico_id' => $candidate->id,
                'candidato_nome' => $candidate->nome_urna,
                'partido_sigla' => 'ABC',
                'percentual' => $percentual,
            ]);
        }

        // Presente só para provar que deixa de ser usada: se o painel caísse
        // de volta ao ElectioLab por engano, este valor destoante apareceria.
        MediaPesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'average-electiolab',
            'external_election_id' => 'election-own',
            'external_candidate_id' => 'candidate-own',
            'candidato_politico_id' => $candidate->id,
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'governador',
            'candidato_nome' => $candidate->nome_urna,
            'partido_sigla' => 'ABC',
            'media_ponderada' => 99.9,
            'pesquisas_incluidas' => 5,
            'amostra_total' => 5000,
            'fonte_url' => 'https://electiolab.test/api/v1/averages',
        ]);

        $this->actingAs($councilor)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('polls.offices.1.slug', 'governador')
                ->where('polls.offices.1.has_aggregate', true)
                ->where('polls.offices.1.averages.0.source', 'govnexgab')
                ->where('polls.offices.1.averages.0.percentage', 41.5));
    }

    /**
     * Pesquisa municipal só tem uso enquanto a eleição não aconteceu; depois
     * da apuração o painel troca a pesquisa pelo resultado (ver
     * test_selecting_a_past_municipal_election_shows_its_result_instead_of_polls).
     */
    public function test_panel_exposes_only_mayoral_polls_for_an_upcoming_municipal_election(): void
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '13692',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $office = Gabinete::factory()->create([
            'estado' => 'CE',
            'municipio' => 'Cruz',
            'municipio_eleitoral_id' => $municipality->id,
        ]);
        $councilor = User::factory()->councilor()->forGabinete($office)->create();
        $election = Eleicao::query()->where('ano', 2024)->firstOrFail();
        // A eleição de 2024 já passou; o cenário aqui é o de uma eleição
        // municipal ainda por vir, que é quando a pesquisa importa.
        $election->forceFill(['primeiro_turno_em' => today()->addMonths(6)])->save();
        $candidate = $this->candidate(
            $election,
            '40',
            CandidateScope::Municipal,
            'CE',
            $municipality->id,
            'Prefeito',
        );
        $candidate->favoritos()->forceCreate([
            'gabinete_id' => $office->id,
            'escolhido_por_id' => $councilor->id,
        ]);
        $poll = PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => '60606060-6060-4060-8060-606060606060',
            'external_election_id' => '70707070-7070-4070-8070-707070707070',
            'ano' => 2024,
            'uf' => 'CE',
            'municipio' => 'Cruz',
            'cargo' => 'prefeito',
            'turno' => 1,
            'instituto' => 'Instituto Municipal',
            'publicada_em' => '2024-09-20',
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
        ]);
        ResultadoPesquisaEleitoral::query()->create([
            'pesquisa_eleitoral_id' => $poll->id,
            'external_candidate_id' => '80808080-8080-4080-8080-808080808080',
            'candidato_politico_id' => $candidate->id,
            'candidato_nome' => $candidate->nome_urna,
            'partido_sigla' => 'ABC',
            'percentual' => 48.6,
        ]);

        $this->actingAs($councilor)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('polls.election_type', 'municipal')
                ->where('polls.municipality', 'Cruz')
                ->has('polls.offices', 1)
                ->where('polls.offices.0.slug', 'prefeito')
                ->where('polls.offices.0.scope_label', 'Cruz/CE')
                ->where('polls.offices.0.polls.0.results.0.is_favorite', true));
    }

    private function candidate(
        Eleicao $election,
        string $sequence,
        CandidateScope $scope,
        ?string $state,
        ?int $municipalityId,
        string $office,
    ): CandidatoPolitico {
        return CandidatoPolitico::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => $sequence,
            'abrangencia' => $scope,
            'municipio_eleitoral_id' => $municipalityId,
            'uf' => $state,
            'cargo' => $office,
            'nome' => "Candidato {$sequence}",
            'nome_urna' => "Nome {$sequence}",
            'numero' => $sequence,
            'partido_sigla' => 'ABC',
            'situacao' => 'APTO',
        ]);
    }
}

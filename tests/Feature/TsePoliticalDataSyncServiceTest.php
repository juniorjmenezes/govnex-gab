<?php

namespace Tests\Feature;

use App\Models\CandidatoPolitico;
use App\Models\ComparecimentoEleitoralMunicipio;
use App\Models\Eleicao;
use App\Models\Gabinete;
use App\Models\LocalVotacaoEleitoral;
use App\Models\MunicipioEleitoral;
use App\Models\PesquisaEleitoral;
use App\Models\SecaoEleitoral;
use App\Models\SincronizacaoTse;
use App\Models\VotacaoCandidatoMunicipio;
use App\Models\VotoSecaoCandidato;
use App\Services\Politics\Tse\TseDatasetUrlBuilder;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class TsePoliticalDataSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // TseDatasetDownloader grava cache em storage/app/tse/, que não é
        // limpo por RefreshDatabase — sem isso, um teste que baixa com
        // sucesso poluiria o cache pro próximo teste do mesmo dataset/ano.
        File::deleteDirectory(storage_path('app/tse'));
        parent::tearDown();
    }

    public function test_imports_official_tse_and_ibge_municipality_codes_and_links_office(): void
    {
        $office = Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
        ]);
        $archive = $this->csvArchive('municipio_tse_ibge.csv', [
            [
                'DT_GERACAO',
                'SG_UF',
                'CD_MUNICIPIO_TSE',
                'NM_MUNICIPIO_TSE',
                'CD_MUNICIPIO_IBGE',
                'NM_MUNICIPIO_IBGE',
            ],
            ['26/07/2026', 'CE', '15890', 'Cruz', '2304251', 'Cruz'],
        ]);

        try {
            $this->assertSame(
                1,
                app(TsePoliticalDataSyncService::class)->importMunicipalities($archive),
            );
        } finally {
            @unlink($archive);
        }

        $municipality = MunicipioEleitoral::query()->firstOrFail();
        $this->assertSame('15890', $municipality->codigo_tse);
        $this->assertSame('2304251', $municipality->codigo_ibge);
        $this->assertSame($municipality->id, $office->fresh()->municipio_eleitoral_id);
    }

    public function test_imports_electorate_and_candidates_for_the_whole_country_not_just_registered_offices(): void
    {
        // O vínculo do gabinete ao município (municipio_eleitoral_id) não é
        // mais responsabilidade do importador — isso é resolvido de forma
        // síncrona no cadastro/edição (OfficeController::resolveMunicipality).
        // Esta importação em si não depende de nenhum gabinete existir.
        $service = app(TsePoliticalDataSyncService::class);
        $this->fakeGovnexElectorateCatalog(2026, ['CE' => 'perfil-eleitorado-ce-2026']);
        $this->fakeGovnexElectorateRecords('perfil-eleitorado-ce-2026', [
            ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '13692', 'NM_MUNICIPIO' => 'CRUZ', 'QT_ELEITORES' => '100'],
            ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '13692', 'NM_MUNICIPIO' => 'CRUZ', 'QT_ELEITORES' => '250'],
            ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '13730', 'NM_MUNICIPIO' => 'FORTALEZA', 'QT_ELEITORES' => '500000'],
        ]);

        // Nem Cruz nem Fortaleza têm gabinete cadastrado, mas os dados
        // dos dois são importados do mesmo jeito — 2 municípios
        // distintos no total.
        $this->assertSame(2, $service->importElectorate(2026));

        $this->assertDatabaseHas('eleitorado_municipio_snapshots', [
            'eleitores_aptos' => 350,
            // upsert() em lote grava a string de data crua (sem hora) —
            // ao contrário do updateOrCreate() anterior, que passava pelo
            // cast 'date' do Eloquent e (só sob a tipagem fraca do SQLite
            // de teste) acabava persistindo com "00:00:00" no final.
            'data_referencia' => '2026-07-20',
        ]);
        $this->assertDatabaseHas('eleitorado_municipio_snapshots', [
            'eleitores_aptos' => 500000,
        ]);

        $candidatesArchive = $this->csvArchive('consulta_cand_2026_BRASIL.csv', [
            [
                'SQ_CANDIDATO',
                'DS_CARGO',
                'SG_UF',
                'SG_UE',
                'DT_GERACAO',
                'HH_GERACAO',
                'NM_CANDIDATO',
                'NM_URNA_CANDIDATO',
                'NR_CANDIDATO',
                'SG_PARTIDO',
                'NM_PARTIDO',
                'DS_SITUACAO_CANDIDATURA',
                'DS_DETALHE_SITUACAO_CAND',
            ],
            ['1', 'PRESIDENTE', 'BR', 'BR', '29/07/2026', '08:00:00', 'NOME UM', 'UM', '10', 'ABC', 'PARTIDO ABC', 'APTO', 'DEFERIDO'],
            ['2', 'GOVERNADOR', 'CE', 'CE', '29/07/2026', '08:00:00', 'NOME DOIS', 'DOIS', '20', 'DEF', 'PARTIDO DEF', 'APTO', 'DEFERIDO'],
            ['3', 'GOVERNADOR', 'SP', 'SP', '29/07/2026', '08:00:00', 'NOME TRES', 'TRES', '30', 'GHI', 'PARTIDO GHI', 'APTO', 'DEFERIDO'],
            ['4', 'VICE-GOVERNADOR', 'CE', 'CE', '29/07/2026', '08:00:00', 'NOME QUATRO', 'QUATRO', '40', 'JKL', 'PARTIDO JKL', 'APTO', 'DEFERIDO'],
        ]);

        try {
            // SP não tem gabinete nenhum cadastrado, mas o candidato a
            // governador de lá é importado do mesmo jeito — só
            // VICE-GOVERNADOR fica de fora, porque candidateScope() não
            // reconhece esse cargo (motivo diferente, não tem a ver com
            // gabinete cadastrado).
            $this->assertSame(3, $service->importCandidates($candidatesArchive, 2026));
        } finally {
            @unlink($candidatesArchive);
        }

        $this->assertSame(3, CandidatoPolitico::query()->count());
        $this->assertSame(
            ['DOIS', 'TRES', 'UM'],
            CandidatoPolitico::query()->orderBy('nome_urna')->pluck('nome_urna')->all(),
        );
    }

    /**
     * Regressão: importElectorate() precisa seguir o cursor de paginação da
     * GOVNEX API (links.next) até o fim — parar na primeira página
     * descartaria o eleitorado das páginas seguintes de um dataset grande
     * (cada UF pode ter centenas de milhares de linhas, bem além do
     * per_page de uma única página).
     */
    public function test_electorate_import_follows_cursor_pagination_across_multiple_pages(): void
    {
        $this->fakeGovnexElectorateCatalog(2026, ['CE' => 'perfil-eleitorado-ce-2026']);
        Http::fake([
            '127.0.0.1:8020/api/v1/sources/tse/datasets/perfil-eleitorado-ce-2026/records*' => function ($request) {
                if (str_contains((string) $request->url(), 'cursor=next-page')) {
                    return Http::response([
                        'data' => [
                            ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '15890', 'NM_MUNICIPIO' => 'CRUZ', 'QT_ELEITORES' => '250'],
                        ],
                        'links' => ['next' => null],
                    ]);
                }

                return Http::response([
                    'data' => [
                        ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '15890', 'NM_MUNICIPIO' => 'CRUZ', 'QT_ELEITORES' => '100'],
                    ],
                    'links' => ['next' => 'http://127.0.0.1:8020/api/v1/sources/tse/datasets/perfil-eleitorado-ce-2026/records?cursor=next-page'],
                ]);
            },
        ]);

        $processed = app(TsePoliticalDataSyncService::class)->importElectorate(2026);

        $this->assertSame(1, $processed);
        $this->assertDatabaseHas('eleitorado_municipio_snapshots', [
            'eleitores_aptos' => 350,
        ]);
    }

    /**
     * Regressão: importElectorate() precisa falhar alto (não gravar um
     * eleitorado vazio em silêncio) quando a GOVNEX API ainda não tem
     * nenhum dataset "Perfil eleitorado" publicado pro ano pedido.
     */
    public function test_electorate_import_fails_when_govnex_api_has_no_dataset_for_the_year(): void
    {
        $this->fakeGovnexElectorateCatalog(2026, []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nenhum dataset de eleitorado publicado');

        app(TsePoliticalDataSyncService::class)->importElectorate(2026);
    }

    /**
     * Rede de segurança: a plausibilidade agora é checada por UF, contra a
     * base de municípios TSE/IBGE já importada localmente — não mais
     * contra uma faixa fixa pro Brasil inteiro (essa versão travava com
     * cobertura parcial legítima, ver histórico desta função). Aqui o CE
     * "deveria" ter 5 municípios (pré-carregados) mas o eleitorado só
     * trouxe 1 — bem fora da tolerância.
     */
    public function test_electorate_import_rejects_an_implausible_municipality_count(): void
    {
        $this->seedMunicipalityReference('CE', 5);
        $this->fakeGovnexElectorateCatalog(2026, ['CE' => 'perfil-eleitorado-ce-2026']);
        $this->fakeGovnexElectorateRecords('perfil-eleitorado-ce-2026', [
            ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '15890', 'NM_MUNICIPIO' => 'CRUZ', 'QT_ELEITORES' => '350'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('esperado ~5 pela base de municípios já importada');

        app(TsePoliticalDataSyncService::class)->importElectorate(2026);
    }

    /**
     * Sem uma base de municípios local pra essa UF, não dá pra distinguir
     * "eleitorado incompleto" de "base ainda não importada" — a checagem é
     * pulada (com log de aviso), não bloqueia a importação.
     */
    public function test_electorate_import_skips_plausibility_check_without_a_local_municipality_reference(): void
    {
        $this->fakeGovnexElectorateCatalog(2026, ['CE' => 'perfil-eleitorado-ce-2026']);
        $this->fakeGovnexElectorateRecords('perfil-eleitorado-ce-2026', [
            ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '15890', 'NM_MUNICIPIO' => 'CRUZ', 'QT_ELEITORES' => '350'],
        ]);

        $processed = app(TsePoliticalDataSyncService::class)->importElectorate(2026);

        $this->assertSame(1, $processed);
    }

    /**
     * Regressão: um dataset catalogado como uma UF (metadata.uf) na GOVNEX
     * API não é confiável cegamente — se as linhas trouxerem outra UF (ex.:
     * marcação automática errada, ou CSV que misturou estados), a
     * importação precisa falhar alto em vez de gravar eleitorado sob a UF
     * errada.
     */
    public function test_electorate_import_rejects_a_dataset_whose_rows_do_not_match_its_declared_uf(): void
    {
        $this->fakeGovnexElectorateCatalog(2026, ['CE' => 'perfil-eleitorado-ce-2026']);
        $this->fakeGovnexElectorateRecords('perfil-eleitorado-ce-2026', [
            ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '15890', 'NM_MUNICIPIO' => 'CRUZ', 'QT_ELEITORES' => '350'],
            ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'BA', 'CD_MUNICIPIO' => '99999', 'NM_MUNICIPIO' => 'SALVADOR', 'QT_ELEITORES' => '100'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('catalogado como CE na GOVNEX API, mas trouxe linhas de outra UF (BA)');

        app(TsePoliticalDataSyncService::class)->importElectorate(2026);
    }

    public function test_imports_turnout_once_per_zone_without_duplicating_candidate_offices(): void
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890',
            'codigo_ibge' => '2304251',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $office = Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
        ]);
        $archive = $this->csvArchive('detalhe_votacao_munzona_2024_BRASIL.csv', [
            [
                'DT_GERACAO', 'HH_GERACAO', 'ANO_ELEICAO', 'CD_TIPO_ELEICAO',
                'NR_TURNO', 'CD_ELEICAO', 'DT_ELEICAO', 'SG_UF', 'SG_UE',
                'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'DS_CARGO',
                'QT_APTOS', 'QT_COMPARECIMENTO', 'QT_ABSTENCOES', 'ST_VOTO_EM_TRANSITO',
            ],
            ['31/07/2026', '02:17:56', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '30', 'Prefeito', '1000', '800', '200', 'N'],
            ['31/07/2026', '02:17:56', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '30', 'Vereador', '1000', '800', '200', 'N'],
            ['31/07/2026', '02:17:56', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '31', 'Prefeito', '500', '400', '100', 'N'],
            ['31/07/2026', '02:17:56', '2024', '2', '1', '619', '06/10/2024', 'CE', '13730', '13730', 'CAUCAIA', '1', 'Prefeito', '2000', '1500', '500', 'N'],
        ]);

        try {
            $processed = app(TsePoliticalDataSyncService::class)->importTurnout(
                $archive,
                2024,
                'https://cdn.tse.jus.br/resultados-2024.zip',
            );
        } finally {
            @unlink($archive);
        }

        // Caucaia não tem gabinete nenhum cadastrado, mas o comparecimento
        // de lá é importado do mesmo jeito — só Cruz e Caucaia são 2
        // municípios distintos no total.
        $this->assertSame(2, $processed);
        $turnout = ComparecimentoEleitoralMunicipio::query()
            ->where('municipio_eleitoral_id', $municipality->id)
            ->firstOrFail();
        $this->assertSame(1500, $turnout->eleitores_aptos);
        $this->assertSame(1200, $turnout->comparecimento);
        $this->assertSame(300, $turnout->abstencoes);
        $this->assertSame(1, $turnout->turno);
        $this->assertSame('2024-10-06', $turnout->data_eleicao->toDateString());
        $this->assertDatabaseHas('comparecimentos_eleitorais_municipio', [
            'eleitores_aptos' => 2000,
            'comparecimento' => 1500,
            'abstencoes' => 500,
        ]);
    }

    public function test_imports_nominal_votes_and_links_office_holder_by_candidate_number(): void
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890',
            'codigo_ibge' => '2304251',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $office = Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
            'numero_eleitoral' => '11555',
        ]);
        // Sigla de UF (não o agregado nacional _BRASIL.csv) — importCandidateVotes()
        // agora processa um estado por vez, um arquivo por UF, pra não
        // acumular o Brasil inteiro em memória (ver docblock do método).
        $archive = $this->csvArchive('votacao_candidato_munzona_2024_CE.csv', [
            [
                'DT_GERACAO', 'HH_GERACAO', 'ANO_ELEICAO', 'CD_TIPO_ELEICAO',
                'NR_TURNO', 'CD_ELEICAO', 'DT_ELEICAO', 'SG_UF', 'SG_UE',
                'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'DS_CARGO',
                'SQ_CANDIDATO', 'NR_CANDIDATO', 'NM_CANDIDATO', 'NM_URNA_CANDIDATO',
                'SG_PARTIDO', 'NM_PARTIDO', 'DS_SITUACAO_JULGAMENTO',
                'DS_DETALHE_SITUACAO_CAND', 'ST_VOTO_EM_TRANSITO',
                'QT_VOTOS_NOMINAIS', 'QT_VOTOS_NOMINAIS_VALIDOS', 'DS_SIT_TOT_TURNO',
            ],
            ['30/07/2026', '02:17:54', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '30', 'Vereador', '60001945113', '11555', 'MARCOS JOSE SILVEIRA', 'MARCOS SILVEIRA', 'PP', 'PROGRESSISTAS', 'DEFERIDO', 'DEFERIDO', 'N', '500', '500', 'ELEITO POR MÉDIA'],
            ['30/07/2026', '02:17:54', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '31', 'Vereador', '60001945113', '11555', 'MARCOS JOSE SILVEIRA', 'MARCOS SILVEIRA', 'PP', 'PROGRESSISTAS', 'DEFERIDO', 'DEFERIDO', 'N', '276', '276', 'ELEITO POR MÉDIA'],
            ['30/07/2026', '02:17:54', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '30', 'Vereador', '60002244509', '77777', 'GERALDO DOS SANTOS MUNIZ', 'SANTOS', 'SOLIDARIEDADE', 'SOLIDARIEDADE', 'DEFERIDO', 'DEFERIDO', 'N', '1193', '1193', 'NÃO ELEITO'],
        ]);

        try {
            $processed = app(TsePoliticalDataSyncService::class)->importCandidateVotes(
                $archive,
                2024,
                'https://cdn.tse.jus.br/resultados-2024.zip',
            );
        } finally {
            @unlink($archive);
        }

        $this->assertSame(2, $processed);
        $this->assertDatabaseCount('votacoes_candidatos_municipio', 2);
        $office->refresh();
        $this->assertNotNull($office->candidato_titular_id);
        $this->assertSame('11555', $office->candidatoTitular?->numero);

        $vote = VotacaoCandidatoMunicipio::query()
            ->where('candidato_politico_id', $office->candidato_titular_id)
            ->firstOrFail();
        $this->assertSame(776, $vote->votos_nominais);
        $this->assertTrue($vote->eleito);
        $this->assertSame('ELEITO POR MÉDIA', $vote->situacao_totalizacao);
        $this->assertDatabaseHas('votacoes_candidatos_municipio', [
            'votos_nominais' => 1193,
            'situacao_totalizacao' => 'NÃO ELEITO',
            'eleito' => false,
        ]);
    }

    /**
     * O ZIP oficial do TSE traz um arquivo por UF mais um agregado nacional
     * (_BRASIL.csv) com os mesmos dados — importCandidateVotes() precisa ler
     * cada UF separadamente (pra não acumular o Brasil inteiro em memória de
     * uma vez, ver docblock do método) e ignorar o agregado nacional, senão
     * conta cada candidato em dobro.
     */
    public function test_imports_nominal_votes_from_every_state_file_and_ignores_the_national_aggregate(): void
    {
        MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        MunicipioEleitoral::query()->create([
            'codigo_tse' => '71072',
            'nome' => 'São Paulo',
            'uf' => 'SP',
        ]);
        $header = [
            'DT_GERACAO', 'HH_GERACAO', 'ANO_ELEICAO', 'CD_TIPO_ELEICAO',
            'NR_TURNO', 'CD_ELEICAO', 'DT_ELEICAO', 'SG_UF', 'SG_UE',
            'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'DS_CARGO',
            'SQ_CANDIDATO', 'NR_CANDIDATO', 'NM_CANDIDATO', 'NM_URNA_CANDIDATO',
            'SG_PARTIDO', 'NM_PARTIDO', 'DS_SITUACAO_JULGAMENTO',
            'DS_DETALHE_SITUACAO_CAND', 'ST_VOTO_EM_TRANSITO',
            'QT_VOTOS_NOMINAIS', 'QT_VOTOS_NOMINAIS_VALIDOS', 'DS_SIT_TOT_TURNO',
        ];
        $ceRow = ['30/07/2026', '02:17:54', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '30', 'Vereador', '60001945113', '11555', 'MARCOS JOSE SILVEIRA', 'MARCOS SILVEIRA', 'PP', 'PROGRESSISTAS', 'DEFERIDO', 'DEFERIDO', 'N', '500', '500', 'ELEITO POR MÉDIA'];
        $spRow = ['30/07/2026', '02:17:54', '2024', '2', '1', '620', '06/10/2024', 'SP', '71072', '71072', 'SAO PAULO', '1', 'Vereador', '70002233445', '99999', 'JOANA DA SILVA', 'JOANA', 'PT', 'TRABALHADORES', 'DEFERIDO', 'DEFERIDO', 'N', '900', '900', 'ELEITO'];

        $archive = $this->csvArchiveWithEntries([
            'votacao_candidato_munzona_2024_CE.csv' => [$header, $ceRow],
            'votacao_candidato_munzona_2024_SP.csv' => [$header, $spRow],
            // Mesmos dois candidatos de novo, só que no agregado nacional —
            // se o import não ignorar esse arquivo, o teste abaixo veria 4
            // registros de votação em vez de 2.
            'votacao_candidato_munzona_2024_BRASIL.csv' => [$header, $ceRow, $spRow],
        ]);

        try {
            $processed = app(TsePoliticalDataSyncService::class)->importCandidateVotes(
                $archive,
                2024,
                'https://cdn.tse.jus.br/resultados-2024.zip',
            );
        } finally {
            @unlink($archive);
        }

        $this->assertSame(2, $processed);
        $this->assertDatabaseCount('votacoes_candidatos_municipio', 2);
        $this->assertDatabaseHas('votacoes_candidatos_municipio', [
            'votos_nominais' => 500,
            'situacao_totalizacao' => 'ELEITO POR MÉDIA',
        ]);
        $this->assertDatabaseHas('votacoes_candidatos_municipio', [
            'votos_nominais' => 900,
            'situacao_totalizacao' => 'ELEITO',
        ]);
    }

    public function test_imports_polling_locations_grouping_sections_by_same_location(): void
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890',
            'codigo_ibge' => '2304251',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
        ]);
        // Sigla de UF (não o agregado nacional _BRASIL.csv) — importPollingLocations()
        // agora processa um estado por vez, um arquivo por UF, pra não
        // acumular o Brasil inteiro em memória (ver docblock do método).
        // Layout real de 2024: uma única entrada nacional, sem o sufixo
        // _BRASIL usado por outros datasets do TSE.
        $archive = $this->csvArchive('eleitorado_local_votacao_2024.csv', [
            [
                'DT_GERACAO', 'HH_GERACAO', 'AA_ELEICAO', 'DT_ELEICAO', 'SG_UF',
                'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'NR_SECAO',
                'NR_LOCAL_VOTACAO', 'NM_LOCAL_VOTACAO', 'DS_TIPO_LOCAL',
                'DS_ENDERECO', 'NM_BAIRRO', 'NR_CEP', 'NR_LATITUDE',
                'NR_LONGITUDE', 'QT_ELEITOR_SECAO',
            ],
            ['29/10/2024', '02:00:30', '2024', '06/10/2024', 'CE', '15890', 'CRUZ', '30', '0011', '0001', 'ESCOLA MUNICIPAL', 'Convencional', 'RUA A, 10', 'CENTRO', '62595000', '-3.7358662', '-38.5170603', '240'],
            ['29/10/2024', '02:00:30', '2024', '06/10/2024', 'CE', '15890', 'CRUZ', '30', '0012', '0001', 'ESCOLA MUNICIPAL', 'Convencional', 'RUA A, 10', 'CENTRO', '62595000', '-3.7358662', '-38.5170603', '180'],
            ['29/10/2024', '02:00:30', '2024', '06/10/2024', 'CE', '15890', 'CRUZ', '31', '0020', '0002', 'IGREJA SAO JOSE', 'Convencional', 'RUA B, 20', 'CENTRO', '62595000', '-1', '-1', '100'],
            ['29/10/2024', '02:00:30', '2024', '06/10/2024', 'CE', '13730', 'CAUCAIA', '1', '0001', '0001', 'OUTRO MUNICIPIO', 'Convencional', 'RUA C, 30', 'CENTRO', '61600000', '-3.8', '-38.6', '300'],
        ]);

        try {
            $processed = app(TsePoliticalDataSyncService::class)->importPollingLocations(
                $archive,
                2024,
                'https://cdn.tse.jus.br/eleitorado_local_votacao_2024.zip',
            );
        } finally {
            @unlink($archive);
        }

        $this->assertSame(3, $processed);
        $this->assertSame(2, LocalVotacaoEleitoral::query()->count());
        $this->assertSame(3, SecaoEleitoral::query()->count());

        $location = LocalVotacaoEleitoral::query()
            ->where('nr_local_votacao', '0001')
            ->firstOrFail();
        $this->assertSame(2, $location->secoes()->count());
        $this->assertSame('tse', $location->latitude_fonte);
        $this->assertNotNull($location->latitude);

        $withoutCoordinates = LocalVotacaoEleitoral::query()
            ->where('nr_local_votacao', '0002')
            ->firstOrFail();
        $this->assertNull($withoutCoordinates->latitude);
        $this->assertNull($withoutCoordinates->longitude);
        $this->assertNull($withoutCoordinates->latitude_fonte);
    }

    /**
     * Mesma garantia de importCandidateVotes: o ZIP oficial traz um arquivo
     * por UF mais o agregado nacional (_BRASIL.csv) com os mesmos dados —
     * importPollingLocations() precisa ler cada UF separadamente (sem
     * acumular o Brasil inteiro em memória de uma vez) e ignorar o
     * agregado nacional, senão conta cada local/seção em dobro.
     */
    public function test_imports_polling_locations_from_every_state_file_and_ignores_the_national_aggregate(): void
    {
        MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        MunicipioEleitoral::query()->create([
            'codigo_tse' => '71072',
            'nome' => 'São Paulo',
            'uf' => 'SP',
        ]);
        $header = [
            'DT_GERACAO', 'HH_GERACAO', 'AA_ELEICAO', 'DT_ELEICAO', 'SG_UF',
            'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'NR_SECAO',
            'NR_LOCAL_VOTACAO', 'NM_LOCAL_VOTACAO', 'DS_TIPO_LOCAL',
            'DS_ENDERECO', 'NM_BAIRRO', 'NR_CEP', 'NR_LATITUDE',
            'NR_LONGITUDE', 'QT_ELEITOR_SECAO',
        ];
        $ceRow = ['29/10/2024', '02:00:30', '2024', '06/10/2024', 'CE', '15890', 'CRUZ', '30', '0011', '0001', 'ESCOLA MUNICIPAL', 'Convencional', 'RUA A, 10', 'CENTRO', '62595000', '-3.7358662', '-38.5170603', '240'];
        $spRow = ['29/10/2024', '02:00:30', '2024', '06/10/2024', 'SP', '71072', 'SAO PAULO', '1', '0100', '0050', 'ESCOLA ESTADUAL', 'Convencional', 'RUA B, 20', 'CENTRO', '01000000', '-23.55', '-46.63', '350'];

        $archive = $this->csvArchiveWithEntries([
            'eleitorado_local_votacao_2024_CE.csv' => [$header, $ceRow],
            'eleitorado_local_votacao_2024_SP.csv' => [$header, $spRow],
            // Mesmos dois locais de novo, só que no agregado nacional — se
            // o import não ignorar esse arquivo, o teste abaixo veria 4
            // locais/seções em vez de 2.
            'eleitorado_local_votacao_2024_BRASIL.csv' => [$header, $ceRow, $spRow],
        ]);

        try {
            $processed = app(TsePoliticalDataSyncService::class)->importPollingLocations(
                $archive,
                2024,
                'https://cdn.tse.jus.br/eleitorado_local_votacao_2024.zip',
            );
        } finally {
            @unlink($archive);
        }

        $this->assertSame(2, $processed);
        $this->assertSame(2, LocalVotacaoEleitoral::query()->count());
        $this->assertSame(2, SecaoEleitoral::query()->count());
        $this->assertDatabaseHas('locais_votacao_eleitorais', ['nr_local_votacao' => '0001']);
        $this->assertDatabaseHas('locais_votacao_eleitorais', ['nr_local_votacao' => '0050']);
    }

    public function test_imports_section_votes_only_for_the_office_holder_candidate(): void
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
        $archive = $this->csvArchive('votacao_secao_2024_CE.csv', [
            [
                'DT_GERACAO', 'HH_GERACAO', 'ANO_ELEICAO', 'CD_TIPO_ELEICAO',
                'NM_TIPO_ELEICAO', 'NR_TURNO', 'CD_ELEICAO', 'DS_ELEICAO',
                'DT_ELEICAO', 'TP_ABRANGENCIA', 'SG_UF', 'SG_UE', 'NM_UE',
                'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'NR_SECAO',
                'CD_CARGO', 'DS_CARGO', 'NR_VOTAVEL', 'NM_VOTAVEL', 'QT_VOTOS',
                'NR_LOCAL_VOTACAO', 'SQ_CANDIDATO', 'NM_LOCAL_VOTACAO',
                'DS_LOCAL_VOTACAO_ENDERECO',
            ],
            ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0011', '13', 'Vereador', '11555', 'MARCOS SILVEIRA', '500', '0001', '60001945113', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
            ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0012', '13', 'Vereador', '11555', 'MARCOS SILVEIRA', '276', '0001', '60001945113', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
            ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0011', '13', 'Vereador', '77777', 'SANTOS', '1193', '0001', '60002244509', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
            ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0011', '11', 'Prefeito', '11555', 'MARCOS SILVEIRA', '999', '0001', '60001945113', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
            ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0011', '13', 'Vereador', '96', 'VOTO NULO', '10', '0001', '-1', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
        ]);

        Http::fake([
            'cdn.tse.jus.br/*votacao_secao*CE.zip' => Http::response(
                file_get_contents($archive),
                200,
            ),
        ]);

        try {
            $processed = app(TsePoliticalDataSyncService::class)->importSectionVotes(
                2024,
                $office->id,
                null,
                $archive,
                'CE',
            );
        } finally {
            @unlink($archive);
        }

        $this->assertSame(2, $processed);
        $this->assertSame(2, VotoSecaoCandidato::query()->count());
        $this->assertSame(776, (int) VotoSecaoCandidato::query()->sum('votos'));
        $this->assertSame(1, LocalVotacaoEleitoral::query()->count());
        $this->assertSame(2, SecaoEleitoral::query()->count());

        $location = LocalVotacaoEleitoral::query()->firstOrFail();
        $this->assertSame('ESCOLA MUNICIPAL', $location->nome);
        $this->assertNull($location->latitude);
    }

    public function obsolete_section_votes_failure_in_one_uf_does_not_discard_progress_from_others(): void
    {
        $ce = MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890',
            'codigo_ibge' => '2304251',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $sp = MunicipioEleitoral::query()->create([
            'codigo_tse' => '71072',
            'codigo_ibge' => '3550308',
            'nome' => 'Sao Paulo',
            'uf' => 'SP',
        ]);
        $election = Eleicao::query()
            ->where('ano', 2024)
            ->where('tipo', 'municipal')
            ->firstOrFail();
        $titularCe = CandidatoPolitico::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => '60001945113',
            'abrangencia' => 'municipal',
            'municipio_eleitoral_id' => $ce->id,
            'uf' => 'CE',
            'cargo' => 'Vereador',
            'nome' => 'MARCOS JOSE SILVEIRA',
            'nome_urna' => 'MARCOS SILVEIRA',
            'numero' => '11555',
            'partido_sigla' => 'PP',
        ]);
        $titularSp = CandidatoPolitico::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => '60009988776',
            'abrangencia' => 'municipal',
            'municipio_eleitoral_id' => $sp->id,
            'uf' => 'SP',
            'cargo' => 'Vereador',
            'nome' => 'ANA PAULA COSTA',
            'nome_urna' => 'ANA COSTA',
            'numero' => '22999',
            'partido_sigla' => 'PT',
        ]);
        Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $ce->id,
            'numero_eleitoral' => '11555',
            'candidato_titular_id' => $titularCe->id,
        ]);
        Gabinete::factory()->create([
            'municipio' => 'Sao Paulo',
            'estado' => 'SP',
            'municipio_eleitoral_id' => $sp->id,
            'numero_eleitoral' => '22999',
            'candidato_titular_id' => $titularSp->id,
        ]);
        $ceArchive = $this->csvArchive('votacao_secao_2024_CE.csv', [
            [
                'ANO_ELEICAO', 'NR_TURNO', 'DS_CARGO', 'CD_MUNICIPIO', 'SQ_CANDIDATO',
                'QT_VOTOS', 'NR_ZONA', 'NR_SECAO', 'NR_LOCAL_VOTACAO',
                'NM_LOCAL_VOTACAO', 'DS_LOCAL_VOTACAO_ENDERECO', 'DT_GERACAO', 'HH_GERACAO',
            ],
            ['2024', '1', 'Vereador', '15890', '60001945113', '500', '30', '0011', '0001', 'ESCOLA MUNICIPAL', 'RUA A, 10', '28/10/2024', '11:46:22'],
        ]);

        Http::fake([
            'cdn.tse.jus.br/*votacao_secao*CE.zip' => Http::response(
                file_get_contents($ceArchive),
                200,
            ),
            'cdn.tse.jus.br/*votacao_secao*SP.zip' => Http::response('', 500),
        ]);

        try {
            $exception = null;

            try {
                app(TsePoliticalDataSyncService::class)->importSectionVotes(2024);
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }
        } finally {
            @unlink($ceArchive);
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('SP', $exception->getMessage());
        $this->assertStringContainsString('1 de 2 UF', $exception->getMessage());
        $this->assertSame(1, VotoSecaoCandidato::query()->count());
        $this->assertDatabaseHas('votos_secao_candidato', [
            'candidato_politico_id' => $titularCe->id,
            'votos' => 500,
        ]);
    }

    public function obsolete_marks_a_sync_as_failed_when_the_tse_layout_processes_no_records(): void
    {
        $archive = $this->csvArchive('municipio_tse_ibge.csv', [[
            'DT_GERACAO',
            'SG_UF',
            'CD_MUNICIPIO_TSE',
            'NM_MUNICIPIO_TSE',
            'CD_MUNICIPIO_IBGE',
            'NM_MUNICIPIO_IBGE',
        ]]);
        Http::fake([
            'cdn.tse.jus.br/*' => Http::response(file_get_contents($archive), 200),
        ]);

        try {
            app(TsePoliticalDataSyncService::class)->sync(2026, 'municipalities');
            $this->fail('A sincronização vazia deveria falhar.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('não processou registros', $exception->getMessage());
        } finally {
            @unlink($archive);
        }

        $run = SincronizacaoTse::query()->firstOrFail();
        $this->assertSame('falhou', $run->situacao);
        $this->assertSame(0, $run->registros_processados);
        $this->assertStringContainsString('não processou registros', (string) $run->erro);
        $this->assertNotNull($run->concluida_em);
    }

    public function obsolete_removes_the_temporary_file_when_the_tse_returns_an_empty_download(): void
    {
        Http::fake([
            'cdn.tse.jus.br/*' => Http::response('', 200),
        ]);
        $pattern = storage_path('app/private/tse/turnout-2024-*.zip');
        $before = glob($pattern) ?: [];

        try {
            app(TsePoliticalDataSyncService::class)->sync(2024, 'turnout');
            $this->fail('O download vazio deveria falhar.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('arquivo vazio', $exception->getMessage());
        }

        $this->assertSame($before, glob($pattern) ?: []);
        $this->assertDatabaseHas('sincronizacoes_tse', [
            'dataset' => 'turnout',
            'ano' => 2024,
            'situacao' => 'falhou',
        ]);
    }

    public function test_poll_registry_backfills_the_official_protocol_on_a_matching_poll(): void
    {
        Gabinete::factory()->create(['estado' => 'CE']);
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $poll = PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'external_election_id' => '11111111-1111-4111-8111-111111111111',
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'governador',
            'instituto' => 'Instituto Opiniao',
            'publicada_em' => '2026-07-30',
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
        ]);
        $archive = $this->csvArchive('pesquisa_eleitoral_2026_CE.csv', [
            ['SG_UF', 'DS_CARGO', 'DT_DIVULGACAO', 'NR_PROTOCOLO_REGISTRO', 'NM_EMPRESA_FANTASIA', 'NM_EMPRESA'],
            ['CE', 'Governador', '2026-07-30 00:00:00', 'CE082262026', 'INSTITUTO OPINIAO', 'INSTITUTO OPINIAO DE GESTAO E PESQUISAS LTDA'],
        ]);

        try {
            $this->assertSame(
                1,
                app(TsePoliticalDataSyncService::class)->importElectionSurveyRegistry($archive, 2026),
            );
        } finally {
            @unlink($archive);
        }

        $this->assertSame('CE082262026', $poll->fresh()->registro_tse);
    }

    public function test_poll_registry_never_overwrites_an_already_linked_protocol(): void
    {
        Gabinete::factory()->create(['estado' => 'CE']);
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $poll = PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'external_election_id' => '11111111-1111-4111-8111-111111111111',
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'governador',
            'instituto' => 'Instituto Opiniao',
            'publicada_em' => '2026-07-30',
            'registro_tse' => 'JA-VINCULADO',
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
        ]);
        $archive = $this->csvArchive('pesquisa_eleitoral_2026_CE.csv', [
            ['SG_UF', 'DS_CARGO', 'DT_DIVULGACAO', 'NR_PROTOCOLO_REGISTRO', 'NM_EMPRESA_FANTASIA', 'NM_EMPRESA'],
            ['CE', 'Governador', '2026-07-30 00:00:00', 'CE082262026', 'INSTITUTO OPINIAO', 'INSTITUTO OPINIAO DE GESTAO E PESQUISAS LTDA'],
        ]);

        try {
            $this->assertSame(
                0,
                app(TsePoliticalDataSyncService::class)->importElectionSurveyRegistry($archive, 2026),
            );
        } finally {
            @unlink($archive);
        }

        $this->assertSame('JA-VINCULADO', $poll->fresh()->registro_tse);
    }

    public function test_poll_registry_does_not_link_the_only_local_poll_when_the_institute_differs(): void
    {
        Gabinete::factory()->create(['estado' => 'CE']);
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $poll = PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'abababab-abab-4bab-8bab-abababababab',
            'external_election_id' => '11111111-1111-4111-8111-111111111111',
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'governador',
            'instituto' => 'Instituto Correto',
            'publicada_em' => '2026-07-30',
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
        ]);
        $archive = $this->csvArchive('pesquisa_eleitoral_2026_CE.csv', [
            ['SG_UF', 'DS_CARGO', 'DT_DIVULGACAO', 'NR_PROTOCOLO_REGISTRO', 'NM_EMPRESA_FANTASIA', 'NM_EMPRESA'],
            ['CE', 'Governador', '2026-07-30 00:00:00', 'CE099992026', 'OUTRO INSTITUTO', 'OUTRO INSTITUTO LTDA'],
        ]);

        try {
            $this->assertSame(
                0,
                app(TsePoliticalDataSyncService::class)->importElectionSurveyRegistry($archive, 2026),
            );
        } finally {
            @unlink($archive);
        }

        $this->assertNull($poll->fresh()->registro_tse);
    }

    public function test_poll_registry_disambiguates_same_day_polls_by_institute_name(): void
    {
        Gabinete::factory()->create(['estado' => 'CE']);
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $pollA = PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'external_election_id' => '11111111-1111-4111-8111-111111111111',
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'governador',
            'instituto' => 'Instituto Opiniao',
            'publicada_em' => '2026-07-30',
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
        ]);
        $pollB = PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'external_election_id' => '11111111-1111-4111-8111-111111111111',
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'governador',
            'instituto' => 'Instituto Vox',
            'publicada_em' => '2026-07-30',
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
        ]);
        $archive = $this->csvArchive('pesquisa_eleitoral_2026_CE.csv', [
            ['SG_UF', 'DS_CARGO', 'DT_DIVULGACAO', 'NR_PROTOCOLO_REGISTRO', 'NM_EMPRESA_FANTASIA', 'NM_EMPRESA'],
            ['CE', 'Governador', '2026-07-30 00:00:00', 'CE082262026', 'INSTITUTO OPINIAO', ''],
            ['CE', 'Governador', '2026-07-30 00:00:00', 'CE099992026', 'INSTITUTO VOX', ''],
        ]);

        try {
            $this->assertSame(
                2,
                app(TsePoliticalDataSyncService::class)->importElectionSurveyRegistry($archive, 2026),
            );
        } finally {
            @unlink($archive);
        }

        $this->assertSame('CE082262026', $pollA->fresh()->registro_tse);
        $this->assertSame('CE099992026', $pollB->fresh()->registro_tse);
    }

    public function test_poll_registry_links_one_registration_covering_multiple_offices_to_each_poll(): void
    {
        // O TSE registra um único questionário cobrindo mais de uma corrida
        // (ex.: "Governador, Senador") numa só linha — o mesmo protocolo
        // deve valer para a pesquisa de cada cargo coberto.
        Gabinete::factory()->create(['estado' => 'CE']);
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $governorPoll = PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'external_election_id' => '11111111-1111-4111-8111-111111111111',
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'governador',
            'instituto' => 'Instituto Opiniao',
            'publicada_em' => '2026-07-30',
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
        ]);
        $senatePoll = PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'external_election_id' => '11111111-1111-4111-8111-111111111111',
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'senador',
            'instituto' => 'Instituto Opiniao',
            'publicada_em' => '2026-07-30',
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
        ]);
        $archive = $this->csvArchive('pesquisa_eleitoral_2026_CE.csv', [
            ['SG_UF', 'DS_CARGO', 'DT_DIVULGACAO', 'NR_PROTOCOLO_REGISTRO', 'NM_EMPRESA_FANTASIA', 'NM_EMPRESA'],
            ['CE', 'Governador, Senador', '2026-07-30 00:00:00', 'CE082262026', 'INSTITUTO OPINIAO', ''],
        ]);

        try {
            $this->assertSame(
                2,
                app(TsePoliticalDataSyncService::class)->importElectionSurveyRegistry($archive, 2026),
            );
        } finally {
            @unlink($archive);
        }

        $this->assertSame('CE082262026', $governorPoll->fresh()->registro_tse);
        $this->assertSame('CE082262026', $senatePoll->fresh()->registro_tse);
    }

    public function test_poll_registry_matches_presidential_polls_nationally(): void
    {
        // Pesquisas de presidente são nacionais (uf=BR no GOVNEX GAB) mesmo que
        // a linha do TSE traga a UF de origem do instituto — nunca ficam de
        // fora só porque nenhum gabinete cadastrado tem aquela UF.
        Gabinete::factory()->create(['estado' => 'CE']);
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $poll = PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'external_election_id' => '22222222-2222-4222-8222-222222222222',
            'ano' => 2026,
            'uf' => 'BR',
            'cargo' => 'presidente',
            'instituto' => 'Instituto Nacional',
            'publicada_em' => '2026-07-20',
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
        ]);
        $archive = $this->csvArchive('pesquisa_eleitoral_2026_BRASIL.csv', [
            ['SG_UF', 'DS_CARGO', 'DT_DIVULGACAO', 'NR_PROTOCOLO_REGISTRO', 'NM_EMPRESA_FANTASIA', 'NM_EMPRESA'],
            ['DF', 'Presidente', '2026-07-20 00:00:00', 'BR012342026', 'INSTITUTO NACIONAL', ''],
        ]);

        try {
            $this->assertSame(
                1,
                app(TsePoliticalDataSyncService::class)->importElectionSurveyRegistry($archive, 2026),
            );
        } finally {
            @unlink($archive);
        }

        $this->assertSame('BR012342026', $poll->fresh()->registro_tse);
    }

    public function test_poll_registry_reports_zero_without_failing_when_nothing_matches(): void
    {
        $archive = $this->csvArchive('pesquisa_eleitoral_2026_CE.csv', [
            ['SG_UF', 'DS_CARGO', 'DT_DIVULGACAO', 'NR_PROTOCOLO_REGISTRO', 'NM_EMPRESA_FANTASIA', 'NM_EMPRESA'],
            ['CE', 'Governador', '2026-07-30 00:00:00', 'CE082262026', 'INSTITUTO OPINIAO', ''],
        ]);
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'poll_registry',
            'ano' => 2026,
            'fonte_url' => 'upload-manual://pesquisa_eleitoral_2026.zip',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        $processed = app(TsePoliticalDataSyncService::class)->syncUploadedDataset($run, $archive);

        $this->assertSame(0, $processed);
        $this->assertDatabaseHas('sincronizacoes_tse', [
            'dataset' => 'poll_registry',
            'situacao' => 'concluida',
            'registros_processados' => 0,
        ]);
    }

    public function test_source_url_builds_the_expected_cdn_paths(): void
    {
        $service = app(TsePoliticalDataSyncService::class);
        $urlBuilder = app(TseDatasetUrlBuilder::class);

        $this->assertSame(
            'https://cdn.tse.jus.br/estatistica/sead/odsele/pesquisa_eleitoral/pesquisa_eleitoral_2026.zip',
            $service->sourceUrl('poll_registry', 2026),
        );
        $this->assertSame(
            'https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/consulta_cand_2024.zip',
            $service->sourceUrl('candidates', 2024),
        );
        $this->assertSame(
            'https://cdn.tse.jus.br/estatistica/sead/odsele/votacao_secao/votacao_secao_2024_CE.zip',
            $service->sourceUrl('section_votes', 2024, 'ce'),
        );
        $this->assertSame(
            'https://cdn.tse.jus.br/estatistica/sead/odsele/municipio_tse_ibge/municipio_tse_ibge.zip',
            $service->sourceUrl('municipalities', 2024),
        );
        $this->assertSame(
            'https://cdn.tse.jus.br/estatistica/sead/odsele/perfil_eleitorado/perfil_eleitorado_2024.zip',
            $service->sourceUrl('electorate', 2024),
        );
        $this->assertSame(
            'https://cdn.tse.jus.br/estatistica/sead/odsele/detalhe_votacao_munzona/detalhe_votacao_munzona_2024.zip',
            $service->sourceUrl('turnout', 2024),
        );
        $this->assertSame(
            'https://cdn.tse.jus.br/estatistica/sead/odsele/votacao_candidato_munzona/votacao_candidato_munzona_2024.zip',
            $service->sourceUrl('candidate_votes', 2024),
        );
        $this->assertSame(
            'https://cdn.tse.jus.br/estatistica/sead/odsele/eleitorado_locais_votacao/eleitorado_local_votacao_2024.zip',
            $service->sourceUrl('polling_locations', 2024),
        );
        $this->assertSame(
            'https://cdn.tse.jus.br/estatistica/sead/odsele/votacao_secao/votacao_secao_{year}_{uf}.zip',
            $urlBuilder->officialTemplate('section_votes'),
        );
    }

    public function test_dataset_history_key_distinguishes_uf_for_uf_scoped_datasets(): void
    {
        $this->assertNotSame(
            TsePoliticalDataSyncService::datasetHistoryKey('section_votes', 2024, 'CE'),
            TsePoliticalDataSyncService::datasetHistoryKey('section_votes', 2024, 'SP'),
        );
        $this->assertSame(
            TsePoliticalDataSyncService::datasetHistoryKey('section_votes', 2024, 'ce'),
            TsePoliticalDataSyncService::datasetHistoryKey('section_votes', 2024, 'CE'),
        );
        // Sem UF, um dataset comum continua com uma única chave por ano.
        $this->assertSame(
            TsePoliticalDataSyncService::datasetHistoryKey('candidates', 2026),
            TsePoliticalDataSyncService::datasetHistoryKey('candidates', 2026, null),
        );
    }

    public function test_syncs_section_votes_from_a_retained_archive_once_the_titular_is_resolved(): void
    {
        // Eleitorado, candidatos, comparecimento, votação nominal e locais
        // de votação já cobrem o Brasil inteiro (não precisam de
        // reprocessamento por gabinete) — só votação por seção continua
        // dependendo do titular já resolvido, e é a única coisa que
        // syncOfficeFromRetainedArchives ainda faz.
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890', 'codigo_ibge' => '2304251', 'nome' => 'Cruz', 'uf' => 'CE',
        ]);
        $election = Eleicao::query()->where('ano', 2024)->where('tipo', 'municipal')->firstOrFail();
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
        $archive = $this->csvArchive('votacao_secao_2024_CE.csv', [
            [
                'DT_GERACAO', 'HH_GERACAO', 'ANO_ELEICAO', 'CD_TIPO_ELEICAO',
                'NM_TIPO_ELEICAO', 'NR_TURNO', 'CD_ELEICAO', 'DS_ELEICAO',
                'DT_ELEICAO', 'TP_ABRANGENCIA', 'SG_UF', 'SG_UE', 'NM_UE',
                'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'NR_SECAO',
                'CD_CARGO', 'DS_CARGO', 'NR_VOTAVEL', 'NM_VOTAVEL', 'QT_VOTOS',
                'NR_LOCAL_VOTACAO', 'SQ_CANDIDATO', 'NM_LOCAL_VOTACAO',
                'DS_LOCAL_VOTACAO_ENDERECO',
            ],
            ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0011', '13', 'Vereador', '11555', 'MARCOS SILVEIRA', '500', '0001', '60001945113', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
        ]);
        SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'section_votes',
            'ano' => 2024,
            'uf' => 'CE',
            'fonte_url' => 'https://cdn.tse.jus.br/estatistica/sead/odsele/votacao_secao/votacao_secao_2024_CE.zip',
            'situacao' => 'concluida',
            'iniciada_em' => now(),
            'concluida_em' => now(),
            'arquivo_retido_path' => $archive,
        ]);

        try {
            $results = app(TsePoliticalDataSyncService::class)->syncOfficeFromRetainedArchives($office);
        } finally {
            @unlink($archive);
        }

        $this->assertSame(['section_votes' => 1], $results);
        $this->assertSame(1, VotoSecaoCandidato::query()->count());
        $this->assertSame(500, (int) VotoSecaoCandidato::query()->sum('votos'));
    }

    public function test_sync_from_retained_archives_is_a_no_op_without_a_resolved_titular(): void
    {
        $office = Gabinete::factory()->create(['candidato_titular_id' => null]);

        $results = app(TsePoliticalDataSyncService::class)->syncOfficeFromRetainedArchives($office);

        $this->assertSame([], $results);
    }

    public function obsolete_download_succeeds_with_a_valid_zip_response(): void
    {
        $archive = $this->csvArchive('municipio_tse_ibge.csv', [
            ['CD_MUNICIPIO_TSE', 'CD_MUNICIPIO_IBGE', 'NM_MUNICIPIO_TSE', 'SG_UF'],
            ['13692', '2303501', 'CRUZ', 'CE'],
        ]);
        Http::fake([
            'cdn.tse.jus.br/*' => Http::response((string) file_get_contents($archive), 200),
        ]);

        try {
            $processed = app(TsePoliticalDataSyncService::class)->sync(2024, 'municipalities');
        } finally {
            @unlink($archive);
        }

        $this->assertSame(['municipalities' => 1], $processed);
        $this->assertDatabaseHas('sincronizacoes_tse', [
            'dataset' => 'municipalities',
            'situacao' => 'concluida',
        ]);
    }

    public function obsolete_download_reports_a_readable_error_when_the_tse_cdn_returns_403(): void
    {
        $accessDeniedHtml = "<HTML><HEAD>\n<TITLE>Access Denied</TITLE>\n</HEAD><BODY>\n"
            .'<H1>Access Denied</H1>'
            ."\nYou don't have permission to access \"http://cdn.tse.jus.br/...\" on this server.\n"
            .'</BODY></HTML>';
        Http::fake([
            'cdn.tse.jus.br/*' => Http::response($accessDeniedHtml, 403),
        ]);

        try {
            app(TsePoliticalDataSyncService::class)->sync(2024, 'municipalities');
            $this->fail('Esperava uma exceção por causa do 403.');
        } catch (\Throwable $exception) {
            // A mensagem operacional (a que vira SincronizacaoTse.erro,
            // visível ao admin) deve ser curta e acionável — nunca despejar
            // o corpo da resposta (a página de bloqueio da Akamai inteira,
            // com "Access Denied"/"edgesuite.net"/"Reference #..."). Esse
            // conteúdo continua indo pro log (Log::warning), só não pra cá.
            $this->assertStringContainsString('Fontes tentadas', $exception->getMessage());
            $this->assertStringContainsString('oficial: HTTP 403', $exception->getMessage());
            $this->assertStringContainsString('tse:cache-dataset', $exception->getMessage());
            $this->assertStringNotContainsString('Access Denied', $exception->getMessage());
            $this->assertStringNotContainsString('edgesuite.net', $exception->getMessage());
            $this->assertStringNotContainsString('<HTML>', $exception->getMessage());
        }

        $this->assertDatabaseHas('sincronizacoes_tse', [
            'dataset' => 'municipalities',
            'situacao' => 'falhou',
        ]);
        $erro = (string) SincronizacaoTse::query()->where('dataset', 'municipalities')->value('erro');
        $this->assertStringContainsString('HTTP 403', $erro);
        $this->assertStringNotContainsString('Access Denied', $erro);
    }

    public function obsolete_download_rejects_html_returned_with_a_200_status_instead_of_a_zip(): void
    {
        Http::fake([
            'cdn.tse.jus.br/*' => Http::response('<html><body>Not really a zip file</body></html>', 200),
        ]);

        try {
            app(TsePoliticalDataSyncService::class)->sync(2024, 'municipalities');
            $this->fail('Esperava uma exceção por conteúdo que não é ZIP.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('não é um arquivo ZIP válido', $exception->getMessage());
            // A mensagem operacional não deve incluir o corpo bruto da
            // resposta — isso fica só no log.
            $this->assertStringNotContainsString('Not really a zip file', $exception->getMessage());
        }
    }

    public function obsolete_download_rejects_an_empty_file(): void
    {
        Http::fake([
            'cdn.tse.jus.br/*' => Http::response('', 200),
        ]);

        try {
            app(TsePoliticalDataSyncService::class)->sync(2024, 'municipalities');
            $this->fail('Esperava uma exceção por arquivo vazio.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('arquivo vazio', $exception->getMessage());
        }
    }

    public function obsolete_download_rejects_a_file_above_the_configured_size_limit(): void
    {
        config()->set('services.tse.max_download_megabytes', 1);
        Http::fake([
            'cdn.tse.jus.br/*' => Http::response(str_repeat('a', 2 * 1024 * 1024), 200),
        ]);

        try {
            app(TsePoliticalDataSyncService::class)->sync(2024, 'municipalities');
            $this->fail('Esperava uma exceção por arquivo acima do limite.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('excede o limite configurado', $exception->getMessage());
        }
    }

    public function obsolete_download_removes_the_temporary_file_after_a_failure(): void
    {
        $directory = storage_path('app/private/tse');
        File::ensureDirectoryExists($directory);
        $before = collect(File::glob("{$directory}/municipalities-2024-*.zip"));

        Http::fake([
            'cdn.tse.jus.br/*' => Http::response('Access Denied', 403),
        ]);

        try {
            app(TsePoliticalDataSyncService::class)->sync(2024, 'municipalities');
        } catch (\Throwable) {
            // esperado — só nos interessa o estado do disco depois.
        }

        $after = collect(File::glob("{$directory}/municipalities-2024-*.zip"));
        $this->assertCount($before->count(), $after->all());
    }

    public function test_sync_uploaded_dataset_processes_a_local_archive_without_touching_the_network(): void
    {
        Http::fake();
        $office = Gabinete::factory()->create(['municipio' => 'Cruz', 'estado' => 'CE']);
        $archive = $this->csvArchive('municipio_tse_ibge.csv', [
            [
                'DT_GERACAO', 'SG_UF', 'CD_MUNICIPIO_TSE', 'NM_MUNICIPIO_TSE',
                'CD_MUNICIPIO_IBGE', 'NM_MUNICIPIO_IBGE',
            ],
            ['26/07/2026', 'CE', '15890', 'Cruz', '2304251', 'Cruz'],
        ]);
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'municipalities',
            'ano' => 2026,
            'fonte_url' => 'upload-manual://municipio_tse_ibge.zip',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        $processed = app(TsePoliticalDataSyncService::class)->syncUploadedDataset($run, $archive);

        $this->assertSame(1, $processed);
        $this->assertSame('concluida', $run->fresh()->situacao);
        $municipality = MunicipioEleitoral::query()->firstOrFail();
        $this->assertSame($municipality->id, $office->fresh()->municipio_eleitoral_id);
        Http::assertNothingSent();
        $this->assertFileDoesNotExist($archive);
    }

    /**
     * Guarda de regressão: o upload manual de polling_locations NUNCA pode
     * passar pelo TseDatasetDownloader novo (CKAN/CDN/mirror) — mesmo sem
     * nenhum mirror configurado e com a rede toda bloqueada, o upload
     * precisa continuar funcionando exatamente como antes dessa mudança.
     */
    public function test_manual_upload_of_polling_locations_never_uses_the_automatic_downloader(): void
    {
        config(['services.tse.dataset_mirror_url' => null]);
        Http::fake();
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890',
            'codigo_ibge' => '2304251',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
        ]);
        $archive = $this->csvArchive('eleitorado_local_votacao_2024_CE.csv', [
            [
                'DT_GERACAO', 'HH_GERACAO', 'AA_ELEICAO', 'DT_ELEICAO', 'SG_UF',
                'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'NR_SECAO',
                'NR_LOCAL_VOTACAO', 'NM_LOCAL_VOTACAO', 'DS_TIPO_LOCAL',
                'DS_ENDERECO', 'NM_BAIRRO', 'NR_CEP', 'NR_LATITUDE',
                'NR_LONGITUDE', 'QT_ELEITOR_SECAO',
            ],
            ['29/10/2024', '02:00:30', '2024', '06/10/2024', 'CE', '15890', 'CRUZ', '30', '0011', '0001', 'ESCOLA MUNICIPAL', 'Convencional', 'RUA A, 10', 'CENTRO', '62595000', '-3.7358662', '-38.5170603', '240'],
        ]);
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'polling_locations',
            'ano' => 2024,
            'fonte_url' => 'upload-manual://eleitorado_local_votacao_2024.zip',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        $processed = app(TsePoliticalDataSyncService::class)->syncUploadedDataset($run, $archive);

        $this->assertSame(1, $processed);
        $this->assertSame('concluida', $run->fresh()->situacao);
        Http::assertNothingSent();
        $this->assertFileDoesNotExist($archive);
    }

    /**
     * O teste mais importante desta camada: com um cache válido, o
     * sincronizador automático nunca deveria bater na rede — mesmo que
     * mirror/oficial/CKAN estivessem todos derrubados (403), o cache tem
     * prioridade absoluta e é resolvido antes de qualquer tentativa de rede.
     */
    public function obsolete_a_cached_polling_locations_dataset_is_used_even_when_every_network_source_would_403(): void
    {
        config(['services.tse.dataset_mirror_url' => null]);
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '15890',
            'codigo_ibge' => '2304251',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
        ]);
        $archive = $this->csvArchive('eleitorado_local_votacao_2024_CE.csv', [
            [
                'DT_GERACAO', 'HH_GERACAO', 'AA_ELEICAO', 'DT_ELEICAO', 'SG_UF',
                'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'NR_SECAO',
                'NR_LOCAL_VOTACAO', 'NM_LOCAL_VOTACAO', 'DS_TIPO_LOCAL',
                'DS_ENDERECO', 'NM_BAIRRO', 'NR_CEP', 'NR_LATITUDE',
                'NR_LONGITUDE', 'QT_ELEITOR_SECAO',
            ],
            ['29/10/2024', '02:00:30', '2024', '06/10/2024', 'CE', '15890', 'CRUZ', '30', '0011', '0001', 'ESCOLA MUNICIPAL', 'Convencional', 'RUA A, 10', 'CENTRO', '62595000', '-3.7358662', '-38.5170603', '240'],
        ]);
        $this->fail('O cache automático do TSE foi removido.');
        @unlink($archive);

        // Rede inteira bloqueada — se qualquer chamada acontecer, o teste
        // falha (via StrayRequestException do Http::fake() sem rotas).
        Http::fake();

        $processed = app(TsePoliticalDataSyncService::class)->sync(2024, 'polling_locations');

        $this->assertSame(['polling_locations' => 1], $processed);
        $this->assertSame(1, LocalVotacaoEleitoral::query()->count());
        Http::assertNothingSent();
    }

    public function test_sync_uploaded_dataset_processes_a_single_uf_for_section_votes(): void
    {
        Http::fake();
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
        Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
            'numero_eleitoral' => '11555',
            'candidato_titular_id' => $titular->id,
        ]);
        $archive = $this->csvArchive('votacao_secao_2024_CE.csv', [
            [
                'DT_GERACAO', 'HH_GERACAO', 'ANO_ELEICAO', 'CD_TIPO_ELEICAO',
                'NM_TIPO_ELEICAO', 'NR_TURNO', 'CD_ELEICAO', 'DS_ELEICAO',
                'DT_ELEICAO', 'TP_ABRANGENCIA', 'SG_UF', 'SG_UE', 'NM_UE',
                'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'NR_SECAO',
                'CD_CARGO', 'DS_CARGO', 'NR_VOTAVEL', 'NM_VOTAVEL', 'QT_VOTOS',
                'NR_LOCAL_VOTACAO', 'SQ_CANDIDATO', 'NM_LOCAL_VOTACAO',
                'DS_LOCAL_VOTACAO_ENDERECO',
            ],
            ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0011', '13', 'Vereador', '11555', 'MARCOS SILVEIRA', '500', '0001', '60001945113', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
            ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0012', '13', 'Vereador', '11555', 'MARCOS SILVEIRA', '276', '0001', '60001945113', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
        ]);
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'section_votes',
            'ano' => 2024,
            'fonte_url' => 'upload-manual://votacao_secao_2024_CE.zip',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        $processed = app(TsePoliticalDataSyncService::class)->syncUploadedDataset($run, $archive, 'CE');

        $this->assertSame(2, $processed);
        $this->assertSame(776, (int) VotoSecaoCandidato::query()->sum('votos'));
        $this->assertSame('concluida', $run->fresh()->situacao);
        Http::assertNothingSent();
        $this->assertFileDoesNotExist($archive);
    }

    public function test_assert_uploaded_archive_is_valid_rejects_a_corrupted_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'govnexgab-invalid-');
        file_put_contents($path, 'isto não é um zip');

        try {
            $this->expectException(RuntimeException::class);
            app(TsePoliticalDataSyncService::class)->assertUploadedArchiveIsValid($path, 'municipalities', 2026);
        } finally {
            @unlink($path);
        }
    }

    public function test_automatic_fallback_records_a_clear_message_when_tse_returns_403(): void
    {
        Http::fake([
            'cdn.tse.jus.br/*' => Http::response('Forbidden', 403),
        ]);
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'municipalities',
            'ano' => 2026,
            'fonte_url' => app(TseDatasetUrlBuilder::class)->official('municipalities', 2026),
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        try {
            app(TsePoliticalDataSyncService::class)->syncFromOfficialSource($run);
            $this->fail('O fallback deveria falhar quando o TSE retorna HTTP 403.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 403', $exception->getMessage());
            $this->assertStringContainsString('upload manual', $exception->getMessage());
        }

        $run->refresh();
        $this->assertSame('falhou', $run->situacao);
        $this->assertStringContainsString('HTTP 403', $run->erro);
    }

    /** Popula a base local de municípios (TSE/IBGE) usada como referência de plausibilidade por UF. */
    private function seedMunicipalityReference(string $uf, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            MunicipioEleitoral::query()->create([
                'codigo_tse' => str_pad((string) (90000 + $i), 5, '0', STR_PAD_LEFT),
                'nome' => "Município {$uf} {$i}",
                'uf' => $uf,
            ]);
        }
    }

    /** @param array<string, string> $datasetsByUf UF (sigla) => slug do dataset */
    private function fakeGovnexElectorateCatalog(int $year, array $datasetsByUf): void
    {
        Http::fake([
            '127.0.0.1:8020/api/v1/sources/tse/datasets' => Http::response([
                'data' => collect($datasetsByUf)
                    ->map(fn (string $slug, string $uf): array => [
                        'slug' => $slug,
                        'year' => $year,
                        'metadata' => ['uf' => $uf],
                        'latest_import_status' => 'completed',
                    ])
                    ->values()
                    ->all(),
            ]),
        ]);
    }

    /** @param list<array<string, string>> $rows */
    private function fakeGovnexElectorateRecords(string $datasetSlug, array $rows): void
    {
        Http::fake([
            "127.0.0.1:8020/api/v1/sources/tse/datasets/{$datasetSlug}/records*" => Http::response([
                'data' => $rows,
                'links' => ['next' => null],
            ]),
        ]);
    }

    private function csvArchive(string $filename, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'govnexgab-tse-');
        $this->assertNotFalse($path);
        $zip = new ZipArchive;
        $this->assertTrue(
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE),
        );
        $contents = collect($rows)
            ->map(fn (array $row): string => implode(';', array_map(
                fn (string $value): string => '"'.str_replace('"', '""', $value).'"',
                $row,
            )))
            ->implode("\r\n");
        $this->assertTrue($zip->addFromString(
            $filename,
            mb_convert_encoding($contents, 'Windows-1252', 'UTF-8'),
        ));
        $this->assertTrue($zip->close());

        return $path;
    }

    /** @param array<string, list<list<string>>> $entriesByFilename */
    private function csvArchiveWithEntries(array $entriesByFilename): string
    {
        $path = tempnam(sys_get_temp_dir(), 'govnexgab-tse-');
        $this->assertNotFalse($path);
        $zip = new ZipArchive;
        $this->assertTrue(
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE),
        );

        foreach ($entriesByFilename as $filename => $rows) {
            $contents = collect($rows)
                ->map(fn (array $row): string => implode(';', array_map(
                    fn (string $value): string => '"'.str_replace('"', '""', $value).'"',
                    $row,
                )))
                ->implode("\r\n");
            $this->assertTrue($zip->addFromString(
                $filename,
                mb_convert_encoding($contents, 'Windows-1252', 'UTF-8'),
            ));
        }

        $this->assertTrue($zip->close());

        return $path;
    }
}

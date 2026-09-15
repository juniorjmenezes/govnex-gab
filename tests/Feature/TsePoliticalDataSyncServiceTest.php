<?php

namespace Tests\Feature;

use App\Jobs\SyncOfficeSectionVotesFromGovnexApi;
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
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Todos os datasets do TSE vêm da GOVNEX API. Os testes publicam cada
 * dataset num catálogo falso com as mesmas colunas do CSV oficial, sob o
 * slug da convenção de GovnexApiDatasetCatalog.
 */
class TsePoliticalDataSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private const MUNICIPALITY_HEADER = [
        'DT_GERACAO', 'SG_UF', 'CD_MUNICIPIO_TSE', 'NM_MUNICIPIO_TSE',
        'CD_MUNICIPIO_IBGE', 'NM_MUNICIPIO_IBGE',
    ];

    private const TURNOUT_HEADER = [
        'DT_GERACAO', 'HH_GERACAO', 'ANO_ELEICAO', 'CD_TIPO_ELEICAO',
        'NR_TURNO', 'CD_ELEICAO', 'DT_ELEICAO', 'SG_UF', 'SG_UE',
        'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'DS_CARGO',
        'QT_APTOS', 'QT_COMPARECIMENTO', 'QT_ABSTENCOES', 'ST_VOTO_EM_TRANSITO',
    ];

    private const CANDIDATE_VOTES_HEADER = [
        'DT_GERACAO', 'HH_GERACAO', 'ANO_ELEICAO', 'CD_TIPO_ELEICAO',
        'NR_TURNO', 'CD_ELEICAO', 'DT_ELEICAO', 'SG_UF', 'SG_UE',
        'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'DS_CARGO',
        'SQ_CANDIDATO', 'NR_CANDIDATO', 'NM_CANDIDATO', 'NM_URNA_CANDIDATO',
        'SG_PARTIDO', 'NM_PARTIDO', 'DS_SITUACAO_JULGAMENTO',
        'DS_DETALHE_SITUACAO_CAND', 'ST_VOTO_EM_TRANSITO',
        'QT_VOTOS_NOMINAIS', 'QT_VOTOS_NOMINAIS_VALIDOS', 'DS_SIT_TOT_TURNO',
    ];

    private const POLLING_LOCATIONS_HEADER = [
        'DT_GERACAO', 'HH_GERACAO', 'AA_ELEICAO', 'DT_ELEICAO', 'SG_UF',
        'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'NR_SECAO',
        'NR_LOCAL_VOTACAO', 'NM_LOCAL_VOTACAO', 'DS_TIPO_LOCAL',
        'DS_ENDERECO', 'NM_BAIRRO', 'NR_CEP', 'NR_LATITUDE',
        'NR_LONGITUDE', 'QT_ELEITOR_SECAO',
    ];

    private const SECTION_VOTES_HEADER = [
        'DT_GERACAO', 'HH_GERACAO', 'ANO_ELEICAO', 'CD_TIPO_ELEICAO',
        'NM_TIPO_ELEICAO', 'NR_TURNO', 'CD_ELEICAO', 'DS_ELEICAO',
        'DT_ELEICAO', 'TP_ABRANGENCIA', 'SG_UF', 'SG_UE', 'NM_UE',
        'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'NR_SECAO',
        'CD_CARGO', 'DS_CARGO', 'NR_VOTAVEL', 'NM_VOTAVEL', 'QT_VOTOS',
        'NR_LOCAL_VOTACAO', 'SQ_CANDIDATO', 'NM_LOCAL_VOTACAO',
        'DS_LOCAL_VOTACAO_ENDERECO',
    ];

    private const POLL_REGISTRY_HEADER = [
        'SG_UF', 'DS_CARGO', 'DT_DIVULGACAO', 'NR_PROTOCOLO_REGISTRO',
        'NM_EMPRESA_FANTASIA', 'NM_EMPRESA',
    ];

    public function test_imports_official_tse_and_ibge_municipality_codes_and_links_office(): void
    {
        $office = Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
        ]);
        $this->fakeGovnexDatasets([
            'municipio-tse-ibge' => $this->records(self::MUNICIPALITY_HEADER, [
                ['26/07/2026', 'CE', '15890', 'Cruz', '2304251', 'Cruz'],
            ]),
        ]);

        $this->assertSame(1, app(TsePoliticalDataSyncService::class)->importMunicipalities());

        $municipality = MunicipioEleitoral::query()->firstOrFail();
        $this->assertSame('15890', $municipality->codigo_tse);
        $this->assertSame('2304251', $municipality->codigo_ibge);
        $this->assertSame($municipality->id, $office->fresh()->municipio_eleitoral_id);
    }

    /**
     * O erro precisa dizer o slug esperado — é com ele que o administrador
     * confere o cadastro do dataset do outro lado.
     */
    public function test_a_dataset_missing_from_the_govnex_api_fails_naming_the_expected_slug(): void
    {
        $this->fakeGovnexDatasets([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('O dataset municipio-tse-ibge não está cadastrado na GOVNEX API. Cadastre-o na fonte TSE com esse nome');

        app(TsePoliticalDataSyncService::class)->importMunicipalities();
    }

    public function test_imports_electorate_and_candidates_for_the_whole_country_not_just_registered_offices(): void
    {
        // O vínculo do gabinete ao município (municipio_eleitoral_id) não é
        // responsabilidade do importador — isso é resolvido de forma
        // síncrona no cadastro/edição (OfficeController::resolveMunicipality).
        // Esta importação em si não depende de nenhum gabinete existir.
        $service = app(TsePoliticalDataSyncService::class);
        $this->fakeGovnexDatasets([
            'perfil-eleitorado-2026-ce' => [
                ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '13692', 'NM_MUNICIPIO' => 'CRUZ', 'QT_ELEITORES' => '100'],
                ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '13692', 'NM_MUNICIPIO' => 'CRUZ', 'QT_ELEITORES' => '250'],
                ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '13730', 'NM_MUNICIPIO' => 'FORTALEZA', 'QT_ELEITORES' => '500000'],
            ],
            'consulta-cand-2026' => $this->records([
                'SQ_CANDIDATO', 'DS_CARGO', 'SG_UF', 'SG_UE', 'DT_GERACAO', 'HH_GERACAO',
                'NM_CANDIDATO', 'NM_URNA_CANDIDATO', 'NR_CANDIDATO', 'SG_PARTIDO',
                'NM_PARTIDO', 'DS_SITUACAO_CANDIDATURA', 'DS_DETALHE_SITUACAO_CAND',
            ], [
                ['1', 'PRESIDENTE', 'BR', 'BR', '29/07/2026', '08:00:00', 'NOME UM', 'UM', '10', 'ABC', 'PARTIDO ABC', 'APTO', 'DEFERIDO'],
                ['2', 'GOVERNADOR', 'CE', 'CE', '29/07/2026', '08:00:00', 'NOME DOIS', 'DOIS', '20', 'DEF', 'PARTIDO DEF', 'APTO', 'DEFERIDO'],
                ['3', 'GOVERNADOR', 'SP', 'SP', '29/07/2026', '08:00:00', 'NOME TRES', 'TRES', '30', 'GHI', 'PARTIDO GHI', 'APTO', 'DEFERIDO'],
                ['4', 'VICE-GOVERNADOR', 'CE', 'CE', '29/07/2026', '08:00:00', 'NOME QUATRO', 'QUATRO', '40', 'JKL', 'PARTIDO JKL', 'APTO', 'DEFERIDO'],
            ]),
        ]);

        // Nem Cruz nem Fortaleza têm gabinete cadastrado, mas os dados dos
        // dois são importados do mesmo jeito — 2 municípios distintos.
        $this->assertSame(2, $service->importElectorate(2026));

        $this->assertDatabaseHas('eleitorado_municipio_snapshots', [
            'eleitores_aptos' => 350,
            'data_referencia' => '2026-07-20',
        ]);
        $this->assertDatabaseHas('eleitorado_municipio_snapshots', [
            'eleitores_aptos' => 500000,
        ]);

        // SP não tem gabinete nenhum cadastrado, mas o candidato a
        // governador de lá é importado do mesmo jeito — só VICE-GOVERNADOR
        // fica de fora, porque candidateScope() não reconhece esse cargo.
        $this->assertSame(3, $service->importCandidates(2026));
        $this->assertSame(
            ['DOIS', 'TRES', 'UM'],
            CandidatoPolitico::query()->orderBy('nome_urna')->pluck('nome_urna')->all(),
        );
    }

    /**
     * Regressão: a leitura precisa seguir o cursor de paginação da GOVNEX
     * API (links.next) até o fim — parar na primeira página descartaria as
     * linhas das páginas seguintes de um dataset grande.
     */
    public function test_electorate_import_follows_cursor_pagination_across_multiple_pages(): void
    {
        $this->fakeGovnexCatalog(['perfil-eleitorado-2026-ce']);
        Http::fake([
            '127.0.0.1:8020/api/v1/sources/tse/datasets/perfil-eleitorado-2026-ce/records*' => function ($request) {
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
                    'links' => ['next' => 'http://127.0.0.1:8020/api/v1/sources/tse/datasets/perfil-eleitorado-2026-ce/records?cursor=next-page'],
                ]);
            },
        ]);

        $processed = app(TsePoliticalDataSyncService::class)->importElectorate(2026);

        $this->assertSame(1, $processed);
        $this->assertDatabaseHas('eleitorado_municipio_snapshots', [
            'eleitores_aptos' => 350,
        ]);
    }

    public function test_electorate_import_fails_when_govnex_api_has_no_dataset_for_the_year(): void
    {
        $this->fakeGovnexDatasets([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nenhum dataset de eleitorado publicado');

        app(TsePoliticalDataSyncService::class)->importElectorate(2026);
    }

    /**
     * A plausibilidade é checada por UF, contra a base de municípios TSE/IBGE
     * já importada localmente. Aqui o CE "deveria" ter 5 municípios mas o
     * eleitorado só trouxe 1 — bem fora da tolerância.
     */
    public function test_electorate_import_rejects_an_implausible_municipality_count(): void
    {
        $this->seedMunicipalityReference('CE', 5);
        $this->fakeGovnexDatasets([
            'perfil-eleitorado-2026-ce' => [
                ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '15890', 'NM_MUNICIPIO' => 'CRUZ', 'QT_ELEITORES' => '350'],
            ],
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
        $this->fakeGovnexDatasets([
            'perfil-eleitorado-2026-ce' => [
                ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '15890', 'NM_MUNICIPIO' => 'CRUZ', 'QT_ELEITORES' => '350'],
            ],
        ]);

        $this->assertSame(1, app(TsePoliticalDataSyncService::class)->importElectorate(2026));
    }

    /**
     * Regressão: a UF declarada no slug do dataset não é confiável cegamente
     * — se as linhas trouxerem outra UF (cadastro com o sufixo errado, ou
     * CSV que misturou estados), a importação falha em vez de gravar
     * eleitorado sob a UF errada.
     */
    public function test_electorate_import_rejects_a_dataset_whose_rows_do_not_match_its_declared_uf(): void
    {
        $this->fakeGovnexDatasets([
            'perfil-eleitorado-2026-ce' => [
                ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'CE', 'CD_MUNICIPIO' => '15890', 'NM_MUNICIPIO' => 'CRUZ', 'QT_ELEITORES' => '350'],
                ['DT_GERACAO' => '20/07/2026', 'SG_UF' => 'BA', 'CD_MUNICIPIO' => '99999', 'NM_MUNICIPIO' => 'SALVADOR', 'QT_ELEITORES' => '100'],
            ],
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
        Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
        ]);
        $this->fakeGovnexDatasets([
            'detalhe-votacao-munzona-2024' => $this->records(self::TURNOUT_HEADER, [
                ['31/07/2026', '02:17:56', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '30', 'Prefeito', '1000', '800', '200', 'N'],
                ['31/07/2026', '02:17:56', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '30', 'Vereador', '1000', '800', '200', 'N'],
                ['31/07/2026', '02:17:56', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '31', 'Prefeito', '500', '400', '100', 'N'],
                ['31/07/2026', '02:17:56', '2024', '2', '1', '619', '06/10/2024', 'CE', '13730', '13730', 'CAUCAIA', '1', 'Prefeito', '2000', '1500', '500', 'N'],
            ]),
        ]);

        $processed = app(TsePoliticalDataSyncService::class)->importTurnout(2024);

        // Caucaia não tem gabinete nenhum cadastrado, mas o comparecimento
        // de lá é importado do mesmo jeito — Cruz e Caucaia são 2 municípios.
        $this->assertSame(2, $processed);
        $turnout = ComparecimentoEleitoralMunicipio::query()
            ->where('municipio_eleitoral_id', $municipality->id)
            ->firstOrFail();
        $this->assertSame(1500, $turnout->eleitores_aptos);
        $this->assertSame(1200, $turnout->comparecimento);
        $this->assertSame(300, $turnout->abstencoes);
        $this->assertSame(1, $turnout->turno);
        $this->assertSame('2024-10-06', $turnout->data_eleicao->toDateString());
        $this->assertSame(
            'http://127.0.0.1:8020/api/v1/sources/tse/datasets/detalhe-votacao-munzona-2024',
            $turnout->fonte_url,
        );
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
        $this->fakeGovnexDatasets([
            'votacao-candidato-munzona-2024' => $this->records(self::CANDIDATE_VOTES_HEADER, [
                ['30/07/2026', '02:17:54', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '30', 'Vereador', '60001945113', '11555', 'MARCOS JOSE SILVEIRA', 'MARCOS SILVEIRA', 'PP', 'PROGRESSISTAS', 'DEFERIDO', 'DEFERIDO', 'N', '500', '500', 'ELEITO POR MÉDIA'],
                ['30/07/2026', '02:17:54', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '31', 'Vereador', '60001945113', '11555', 'MARCOS JOSE SILVEIRA', 'MARCOS SILVEIRA', 'PP', 'PROGRESSISTAS', 'DEFERIDO', 'DEFERIDO', 'N', '276', '276', 'ELEITO POR MÉDIA'],
                ['30/07/2026', '02:17:54', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '30', 'Vereador', '60002244509', '77777', 'GERALDO DOS SANTOS MUNIZ', 'SANTOS', 'SOLIDARIEDADE', 'SOLIDARIEDADE', 'DEFERIDO', 'DEFERIDO', 'N', '1193', '1193', 'NÃO ELEITO'],
                // Prefeito entra junto: mesma tabela, outra disputa.
                ['30/07/2026', '02:17:54', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '30', 'Prefeito', '60003311224', '15', 'MARIA DA SILVA', 'MARIA', 'MDB', 'MOVIMENTO DEMOCRATICO', 'DEFERIDO', 'DEFERIDO', 'N', '4200', '4200', 'ELEITO'],
            ]),
        ], ['votacao-candidato-munzona-2024' => ['SG_UF']]);

        $processed = app(TsePoliticalDataSyncService::class)->importCandidateVotes(2024);

        $this->assertSame(3, $processed);
        $this->assertDatabaseCount('votacoes_candidatos_municipio', 3);
        // O titular continua sendo o vereador do número do gabinete: a
        // resolução não se confunde com o prefeito recém-importado.
        $this->assertDatabaseHas('candidatos_politicos', [
            'sq_candidato' => '60003311224',
            'cargo' => 'Prefeito',
            'nome_urna' => 'MARIA',
        ]);
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

    /** A votação nominal é gravada um estado por vez, sem perder nenhum. */
    public function test_imports_nominal_votes_state_by_state(): void
    {
        MunicipioEleitoral::query()->create(['codigo_tse' => '15890', 'nome' => 'Cruz', 'uf' => 'CE']);
        MunicipioEleitoral::query()->create(['codigo_tse' => '71072', 'nome' => 'São Paulo', 'uf' => 'SP']);
        $this->fakeGovnexDatasets([
            'votacao-candidato-munzona-2024' => $this->records(self::CANDIDATE_VOTES_HEADER, [
                ['30/07/2026', '02:17:54', '2024', '2', '1', '619', '06/10/2024', 'CE', '15890', '15890', 'CRUZ', '30', 'Vereador', '60001945113', '11555', 'MARCOS JOSE SILVEIRA', 'MARCOS SILVEIRA', 'PP', 'PROGRESSISTAS', 'DEFERIDO', 'DEFERIDO', 'N', '500', '500', 'ELEITO POR MÉDIA'],
                ['30/07/2026', '02:17:54', '2024', '2', '1', '620', '06/10/2024', 'SP', '71072', '71072', 'SAO PAULO', '1', 'Vereador', '70002233445', '99999', 'JOANA DA SILVA', 'JOANA', 'PT', 'TRABALHADORES', 'DEFERIDO', 'DEFERIDO', 'N', '900', '900', 'ELEITO'],
            ]),
        ], ['votacao-candidato-munzona-2024' => ['SG_UF']]);

        $processed = app(TsePoliticalDataSyncService::class)->importCandidateVotes(2024);

        $this->assertSame(2, $processed);
        $this->assertDatabaseHas('votacoes_candidatos_municipio', [
            'votos_nominais' => 500,
            'situacao_totalizacao' => 'ELEITO POR MÉDIA',
        ]);
        $this->assertDatabaseHas('votacoes_candidatos_municipio', [
            'votos_nominais' => 900,
            'situacao_totalizacao' => 'ELEITO',
        ]);
    }

    /**
     * O dataset publicado traz as UFs intercaladas (medido: 897 trocas nas
     * primeiras 4 mil linhas), então a leitura é feita por consulta filtrada,
     * um estado por vez. Sem SG_UF declarado como filtrável, o importador para
     * e diz o que falta, em vez de tentar ler o país inteiro de uma vez.
     */
    public function test_nominal_votes_require_the_state_filter_on_the_dataset(): void
    {
        MunicipioEleitoral::query()->create(['codigo_tse' => '15890', 'nome' => 'Cruz', 'uf' => 'CE']);
        $this->fakeGovnexDatasets(['votacao-candidato-munzona-2024' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('precisa declarar SG_UF como campo filtrável');

        app(TsePoliticalDataSyncService::class)->importCandidateVotes(2024);
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
        $this->fakeGovnexDatasets([
            'eleitorado-local-votacao-2024' => $this->records(self::POLLING_LOCATIONS_HEADER, [
                ['29/10/2024', '02:00:30', '2024', '06/10/2024', 'CE', '15890', 'CRUZ', '30', '0011', '0001', 'ESCOLA MUNICIPAL', 'Convencional', 'RUA A, 10', 'CENTRO', '62595000', '-3.7358662', '-38.5170603', '240'],
                ['29/10/2024', '02:00:30', '2024', '06/10/2024', 'CE', '15890', 'CRUZ', '30', '0012', '0001', 'ESCOLA MUNICIPAL', 'Convencional', 'RUA A, 10', 'CENTRO', '62595000', '-3.7358662', '-38.5170603', '180'],
                ['29/10/2024', '02:00:30', '2024', '06/10/2024', 'CE', '15890', 'CRUZ', '31', '0020', '0002', 'IGREJA SAO JOSE', 'Convencional', 'RUA B, 20', 'CENTRO', '62595000', '-1', '-1', '100'],
                ['29/10/2024', '02:00:30', '2024', '06/10/2024', 'CE', '13730', 'CAUCAIA', '1', '0001', '0001', 'OUTRO MUNICIPIO', 'Convencional', 'RUA C, 30', 'CENTRO', '61600000', '-3.8', '-38.6', '300'],
            ]),
        ]);

        $processed = app(TsePoliticalDataSyncService::class)->importPollingLocations(2024);

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

    public function test_imports_section_votes_only_for_the_office_holder_candidate(): void
    {
        $office = $this->officeWithResolvedTitular();
        $this->fakeGovnexDatasets([
            'votacao-secao-2024-ce' => $this->records(self::SECTION_VOTES_HEADER, [
                ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0011', '13', 'Vereador', '11555', 'MARCOS SILVEIRA', '500', '0001', '60001945113', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
                ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0012', '13', 'Vereador', '11555', 'MARCOS SILVEIRA', '276', '0001', '60001945113', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
                ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0011', '13', 'Vereador', '77777', 'SANTOS', '1193', '0001', '60002244509', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
                ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0011', '11', 'Prefeito', '11555', 'MARCOS SILVEIRA', '999', '0001', '60001945113', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
                ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0011', '13', 'Vereador', '96', 'VOTO NULO', '10', '0001', '-1', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
            ]),
        ]);

        $processed = app(TsePoliticalDataSyncService::class)->importSectionVotes(2024, $office->id);

        $this->assertSame(2, $processed);
        $this->assertSame(2, VotoSecaoCandidato::query()->count());
        $this->assertSame(776, (int) VotoSecaoCandidato::query()->sum('votos'));
        $this->assertSame(1, LocalVotacaoEleitoral::query()->count());
        $this->assertSame(2, SecaoEleitoral::query()->count());

        $location = LocalVotacaoEleitoral::query()->firstOrFail();
        $this->assertSame('ESCOLA MUNICIPAL', $location->nome);
        $this->assertNull($location->latitude);
    }

    /**
     * Sincronizada pela tela (sem gabinete específico), a votação por seção
     * precisa dizer quais UFs esperava encontrar quando nenhuma está
     * publicada — publicar outra UF não resolve.
     */
    public function test_section_votes_fail_when_no_state_with_an_office_is_published(): void
    {
        $this->officeWithResolvedTitular();
        $this->fakeGovnexDatasets(['votacao-secao-2024-sp' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nenhum dataset de votação por seção publicado na GOVNEX API para 2024 nas UFs com gabinete (CE)');

        app(TsePoliticalDataSyncService::class)->importSectionVotes(2024);
    }

    public function test_imports_section_votes_for_an_office_once_its_titular_is_resolved(): void
    {
        // Os demais datasets já cobrem o Brasil inteiro (não precisam de
        // reprocessamento por gabinete) — só a votação por seção depende do
        // titular já resolvido.
        $office = $this->officeWithResolvedTitular();
        SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'section_votes',
            'ano' => 2024,
            'fonte_url' => 'http://127.0.0.1:8020/api/v1/sources/*/datasets/votacao-secao-2024-*',
            'situacao' => 'concluida',
            'iniciada_em' => now(),
            'concluida_em' => now(),
        ]);
        $this->fakeGovnexDatasets([
            'votacao-secao-2024-ce' => $this->records(self::SECTION_VOTES_HEADER, [
                ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0011', '13', 'Vereador', '11555', 'MARCOS SILVEIRA', '500', '0001', '60001945113', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
            ]),
        ]);

        $results = app(TsePoliticalDataSyncService::class)->syncOfficeSectionVotes($office);

        $this->assertSame(['section_votes' => 1], $results);
        $this->assertSame(500, (int) VotoSecaoCandidato::query()->sum('votos'));
    }

    public function test_office_section_votes_are_a_no_op_without_a_resolved_titular(): void
    {
        $office = Gabinete::factory()->create(['candidato_titular_id' => null]);

        $this->assertSame([], app(TsePoliticalDataSyncService::class)->syncOfficeSectionVotes($office));
    }

    /**
     * Um gabinete novo não dispara sozinho a importação de um dataset que o
     * administrador nunca sincronizou.
     */
    public function test_office_section_votes_wait_until_the_dataset_was_synced_once(): void
    {
        Http::fake();
        $office = $this->officeWithResolvedTitular();

        $this->assertSame([], app(TsePoliticalDataSyncService::class)->syncOfficeSectionVotes($office));
        Http::assertNothingSent();
    }

    public function test_poll_registry_backfills_the_official_protocol_on_a_matching_poll(): void
    {
        Gabinete::factory()->create(['estado' => 'CE']);
        $poll = $this->poll('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'CE', 'governador', 'Instituto Opiniao', '2026-07-30');
        $this->fakeGovnexDatasets([
            'pesquisa-eleitoral-2026' => $this->records(self::POLL_REGISTRY_HEADER, [
                ['CE', 'Governador', '2026-07-30 00:00:00', 'CE082262026', 'INSTITUTO OPINIAO', 'INSTITUTO OPINIAO DE GESTAO E PESQUISAS LTDA'],
            ]),
        ]);

        $this->assertSame(1, app(TsePoliticalDataSyncService::class)->importElectionSurveyRegistry(2026));
        $this->assertSame('CE082262026', $poll->fresh()->registro_tse);
    }

    public function test_poll_registry_never_overwrites_an_already_linked_protocol(): void
    {
        Gabinete::factory()->create(['estado' => 'CE']);
        $poll = $this->poll('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'CE', 'governador', 'Instituto Opiniao', '2026-07-30', 'JA-VINCULADO');
        $this->fakeGovnexDatasets([
            'pesquisa-eleitoral-2026' => $this->records(self::POLL_REGISTRY_HEADER, [
                ['CE', 'Governador', '2026-07-30 00:00:00', 'CE082262026', 'INSTITUTO OPINIAO', 'INSTITUTO OPINIAO DE GESTAO E PESQUISAS LTDA'],
            ]),
        ]);

        $this->assertSame(0, app(TsePoliticalDataSyncService::class)->importElectionSurveyRegistry(2026));
        $this->assertSame('JA-VINCULADO', $poll->fresh()->registro_tse);
    }

    public function test_poll_registry_does_not_link_the_only_local_poll_when_the_institute_differs(): void
    {
        Gabinete::factory()->create(['estado' => 'CE']);
        $poll = $this->poll('abababab-abab-4bab-8bab-abababababab', 'CE', 'governador', 'Instituto Correto', '2026-07-30');
        $this->fakeGovnexDatasets([
            'pesquisa-eleitoral-2026' => $this->records(self::POLL_REGISTRY_HEADER, [
                ['CE', 'Governador', '2026-07-30 00:00:00', 'CE099992026', 'OUTRO INSTITUTO', 'OUTRO INSTITUTO LTDA'],
            ]),
        ]);

        $this->assertSame(0, app(TsePoliticalDataSyncService::class)->importElectionSurveyRegistry(2026));
        $this->assertNull($poll->fresh()->registro_tse);
    }

    public function test_poll_registry_disambiguates_same_day_polls_by_institute_name(): void
    {
        Gabinete::factory()->create(['estado' => 'CE']);
        $pollA = $this->poll('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'CE', 'governador', 'Instituto Opiniao', '2026-07-30');
        $pollB = $this->poll('dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'CE', 'governador', 'Instituto Vox', '2026-07-30');
        $this->fakeGovnexDatasets([
            'pesquisa-eleitoral-2026' => $this->records(self::POLL_REGISTRY_HEADER, [
                ['CE', 'Governador', '2026-07-30 00:00:00', 'CE082262026', 'INSTITUTO OPINIAO', ''],
                ['CE', 'Governador', '2026-07-30 00:00:00', 'CE099992026', 'INSTITUTO VOX', ''],
            ]),
        ]);

        $this->assertSame(2, app(TsePoliticalDataSyncService::class)->importElectionSurveyRegistry(2026));
        $this->assertSame('CE082262026', $pollA->fresh()->registro_tse);
        $this->assertSame('CE099992026', $pollB->fresh()->registro_tse);
    }

    public function test_poll_registry_links_one_registration_covering_multiple_offices_to_each_poll(): void
    {
        // O TSE registra um único questionário cobrindo mais de uma corrida
        // (ex.: "Governador, Senador") numa só linha — o mesmo protocolo
        // vale para a pesquisa de cada cargo coberto.
        Gabinete::factory()->create(['estado' => 'CE']);
        $governorPoll = $this->poll('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'CE', 'governador', 'Instituto Opiniao', '2026-07-30');
        $senatePoll = $this->poll('ffffffff-ffff-4fff-8fff-ffffffffffff', 'CE', 'senador', 'Instituto Opiniao', '2026-07-30');
        $this->fakeGovnexDatasets([
            'pesquisa-eleitoral-2026' => $this->records(self::POLL_REGISTRY_HEADER, [
                ['CE', 'Governador, Senador', '2026-07-30 00:00:00', 'CE082262026', 'INSTITUTO OPINIAO', ''],
            ]),
        ]);

        $this->assertSame(2, app(TsePoliticalDataSyncService::class)->importElectionSurveyRegistry(2026));
        $this->assertSame('CE082262026', $governorPoll->fresh()->registro_tse);
        $this->assertSame('CE082262026', $senatePoll->fresh()->registro_tse);
    }

    public function test_poll_registry_matches_presidential_polls_nationally(): void
    {
        // Pesquisas de presidente são nacionais (uf=BR no GOVNEX GAB) mesmo que
        // a linha do TSE traga a UF de origem do instituto.
        Gabinete::factory()->create(['estado' => 'CE']);
        $poll = $this->poll('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'BR', 'presidente', 'Instituto Nacional', '2026-07-20');
        $this->fakeGovnexDatasets([
            'pesquisa-eleitoral-2026' => $this->records(self::POLL_REGISTRY_HEADER, [
                ['DF', 'Presidente', '2026-07-20 00:00:00', 'BR012342026', 'INSTITUTO NACIONAL', ''],
            ]),
        ]);

        $this->assertSame(1, app(TsePoliticalDataSyncService::class)->importElectionSurveyRegistry(2026));
        $this->assertSame('BR012342026', $poll->fresh()->registro_tse);
    }

    /**
     * Sem pesquisa local pendente de vínculo, não há o que cruzar: a
     * execução termina concluída com 0, sem nem consultar a GOVNEX API.
     */
    public function test_poll_registry_completes_with_zero_without_calling_the_api_when_nothing_is_pending(): void
    {
        Http::fake();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'poll_registry',
            'ano' => 2026,
            'fonte_url' => 'http://127.0.0.1:8020/api/v1',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        $this->assertSame(0, app(TsePoliticalDataSyncService::class)->syncFromGovnexApi($run));
        $this->assertDatabaseHas('sincronizacoes_tse', [
            'dataset' => 'poll_registry',
            'situacao' => 'concluida',
            'registros_processados' => 0,
        ]);
        Http::assertNothingSent();
    }

    public function test_sync_completes_the_run_with_the_processed_count_and_the_expected_slug(): void
    {
        $this->fakeGovnexDatasets([
            'municipio-tse-ibge' => $this->records(self::MUNICIPALITY_HEADER, [
                ['26/07/2026', 'CE', '15890', 'Cruz', '2304251', 'Cruz'],
            ]),
        ]);
        $run = $this->pendingRun('municipalities', 2026);

        $this->assertSame(1, app(TsePoliticalDataSyncService::class)->syncFromGovnexApi($run));

        $run->refresh();
        $this->assertSame('concluida', $run->situacao);
        $this->assertSame(1, $run->registros_processados);
        $this->assertSame('http://127.0.0.1:8020/api/v1/sources/*/datasets/municipio-tse-ibge', $run->fonte_url);
        $this->assertNotNull($run->concluida_em);
    }

    public function test_sync_records_the_failure_reason_on_the_run(): void
    {
        $this->fakeGovnexDatasets([
            'municipio-tse-ibge' => $this->records(self::MUNICIPALITY_HEADER, [
                ['26/07/2026', 'CE', '', 'Cruz', '', 'Cruz'],
            ]),
        ]);
        $run = $this->pendingRun('municipalities', 2026);

        try {
            app(TsePoliticalDataSyncService::class)->syncFromGovnexApi($run);
            $this->fail('Um dataset sem nenhum município válido deveria falhar.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('não devolveu nenhum município válido', $exception->getMessage());
        }

        $run->refresh();
        $this->assertSame('falhou', $run->situacao);
        $this->assertStringContainsString('não devolveu nenhum município válido', (string) $run->erro);
        $this->assertNotNull($run->concluida_em);
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

    /**
     * Quando a GOVNEX API aceita filtro por candidato, a votação por seção
     * pede só as linhas do titular — uma consulta por gabinete, em vez de ler
     * o estado inteiro (~1,5 milhão de linhas numa UF real).
     */
    public function test_section_votes_are_fetched_per_titular_when_the_dataset_accepts_the_filter(): void
    {
        $office = $this->officeWithResolvedTitular();
        $this->fakeGovnexDatasets([
            'votacao-secao-2024-ce' => $this->records(self::SECTION_VOTES_HEADER, [
                ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0011', '13', 'Vereador', '11555', 'MARCOS SILVEIRA', '500', '0001', '60001945113', 'ESCOLA MUNICIPAL', 'RUA A, 10'],
            ]),
        ], ['votacao-secao-2024-ce' => ['SQ_CANDIDATO']]);

        $processed = app(TsePoliticalDataSyncService::class)->importSectionVotes(2024, $office->id);

        $this->assertSame(1, $processed);
        $this->assertSame(500, (int) VotoSecaoCandidato::query()->sum('votos'));
        Http::assertSent(
            fn ($request): bool => str_contains($request->url(), '/records')
                && str_contains($request->url(), 'SQ_CANDIDATO=60001945113'),
        );
    }

    /**
     * Gabinete cadastrado antes de as candidaturas existirem: assim que a
     * importação resolve o titular dele, a votação por seção daquele gabinete
     * entra na fila — sem o administrador precisar sincronizar de novo.
     */
    public function test_resolving_a_titular_queues_the_section_votes_import_of_that_office(): void
    {
        Queue::fake();
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
            'candidato_titular_id' => null,
        ]);
        $this->fakeGovnexDatasets([
            'consulta-cand-2024' => $this->records([
                'SQ_CANDIDATO', 'DS_CARGO', 'SG_UF', 'SG_UE', 'DT_GERACAO', 'HH_GERACAO',
                'NM_CANDIDATO', 'NM_URNA_CANDIDATO', 'NR_CANDIDATO', 'SG_PARTIDO',
                'NM_PARTIDO', 'DS_SITUACAO_CANDIDATURA', 'DS_DETALHE_SITUACAO_CAND',
            ], [
                ['60001945113', 'Vereador', 'CE', '15890', '29/07/2024', '08:00:00', 'MARCOS JOSE SILVEIRA', 'MARCOS SILVEIRA', '11555', 'PP', 'PROGRESSISTAS', 'APTO', 'DEFERIDO'],
            ]),
        ]);

        app(TsePoliticalDataSyncService::class)->importCandidates(2024);

        $this->assertNotNull($office->fresh()->candidato_titular_id);
        Queue::assertPushed(
            SyncOfficeSectionVotesFromGovnexApi::class,
            fn (SyncOfficeSectionVotesFromGovnexApi $job): bool => $job->officeId === $office->id,
        );
    }

    /** Gabinete em Cruz/CE com o titular (vereador eleito em 2024) já resolvido. */
    private function officeWithResolvedTitular(): Gabinete
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

        return Gabinete::factory()->create([
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
            'numero_eleitoral' => '11555',
            'candidato_titular_id' => $titular->id,
        ]);
    }

    private function poll(
        string $externalId,
        string $uf,
        string $office,
        string $institute,
        string $publishedAt,
        ?string $registration = null,
    ): PesquisaEleitoral {
        return PesquisaEleitoral::query()->create([
            'eleicao_id' => Eleicao::query()->where('ano', 2026)->firstOrFail()->id,
            'external_id' => $externalId,
            'external_election_id' => '11111111-1111-4111-8111-111111111111',
            'ano' => 2026,
            'uf' => $uf,
            'cargo' => $office,
            'instituto' => $institute,
            'publicada_em' => $publishedAt,
            'registro_tse' => $registration,
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
        ]);
    }

    private function pendingRun(string $dataset, int $year): SincronizacaoTse
    {
        return SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => $dataset,
            'ano' => $year,
            'fonte_url' => 'http://127.0.0.1:8020/api/v1',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);
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

    /**
     * Publica datasets sob a fonte `tse` do catálogo falso, cada um com as
     * próprias linhas. Todos os slugs do teste precisam vir numa chamada só:
     * a primeira listagem registrada é a que responde.
     *
     * @param  array<string, list<array<string, string>>>  $recordsBySlug
     * @param  array<string, list<string>>  $filterableBySlug  Colunas que cada
     *                                                         dataset aceita
     *                                                         como filtro
     */
    private function fakeGovnexDatasets(array $recordsBySlug, array $filterableBySlug = []): void
    {
        $this->fakeGovnexCatalog(array_keys($recordsBySlug), $filterableBySlug);

        foreach ($recordsBySlug as $slug => $rows) {
            Http::fake([
                "127.0.0.1:8020/api/v1/sources/tse/datasets/{$slug}/records*" => function ($request) use ($rows) {
                    // A GOVNEX API devolve só as linhas que casam com o filtro
                    // declarado; sem isso aqui, uma leitura por UF traria o
                    // país inteiro a cada consulta.
                    parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
                    $filters = array_diff_key($query, array_flip(['paginate', 'per_page', 'cursor']));
                    $filtered = array_values(array_filter(
                        $rows,
                        function (array $row) use ($filters): bool {
                            foreach ($filters as $field => $value) {
                                if (($row[$field] ?? null) !== $value) {
                                    return false;
                                }
                            }

                            return true;
                        },
                    ));

                    return Http::response(['data' => $filtered, 'links' => ['next' => null]]);
                },
            ]);
        }
    }

    /**
     * Catálogo como o resolvedor o enxerga: a lista de fontes e, sob `tse`,
     * os datasets com o último import concluído.
     *
     * @param  list<string>  $slugs
     * @param  array<string, list<string>>  $filterableBySlug
     */
    private function fakeGovnexCatalog(array $slugs, array $filterableBySlug = []): void
    {
        Http::fake([
            '127.0.0.1:8020/api/v1/sources' => Http::response([
                'data' => [['slug' => 'tse'], ['slug' => 'govnex']],
            ]),
            '127.0.0.1:8020/api/v1/sources/tse/datasets' => Http::response([
                'data' => array_map(
                    fn (string $slug): array => [
                        'slug' => $slug,
                        'latest_import_status' => 'completed',
                        'filterable_fields' => $filterableBySlug[$slug] ?? [],
                    ],
                    $slugs,
                ),
            ]),
            '127.0.0.1:8020/api/v1/sources/govnex/datasets' => Http::response(['data' => []]),
        ]);
    }

    /**
     * Linhas no formato que a GOVNEX API devolve: cada uma com as colunas do
     * CSV oficial como chaves.
     *
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     * @return list<array<string, string>>
     */
    private function records(array $header, array $rows): array
    {
        return array_map(fn (array $row): array => array_combine($header, $row), $rows);
    }
}

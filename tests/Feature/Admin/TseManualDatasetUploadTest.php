<?php

namespace Tests\Feature\Admin;

use App\Jobs\DownloadAndProcessTseDataset;
use App\Jobs\ProcessUploadedTseDataset;
use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\Gabinete;
use App\Models\MunicipioEleitoral;
use App\Models\SincronizacaoTse;
use App\Models\User;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use ZipArchive;

class TseManualDatasetUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // UF fixa (não o sorteio padrão da factory) para casar com os testes
        // abaixo que sobem dados de CE — a validação de UF só aceita estados
        // com gabinete ou entidade cadastrado.
        $this->readySectionVotesOffice('CE', '99998', '99999999998');
    }

    public function test_platform_admin_can_upload_a_valid_dataset_and_it_gets_queued(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'municipalities',
                'arquivo' => $this->fakeZip('municipio_tse_ibge.zip'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $run = SincronizacaoTse::query()->firstOrFail();
        $this->assertNull($run->gabinete_id);
        $this->assertSame($admin->id, $run->solicitado_por_id);
        $this->assertSame('municipalities', $run->dataset);
        $this->assertSame(now()->year, $run->ano);
        $this->assertSame('pendente', $run->situacao);
        $this->assertSame(
            'https://cdn.tse.jus.br/estatistica/sead/odsele/municipio_tse_ibge/municipio_tse_ibge.zip',
            $run->fonte_url,
        );
        Queue::assertPushed(ProcessUploadedTseDataset::class, fn (ProcessUploadedTseDataset $job): bool => $job->runId === $run->id && $job->uf === null);
    }

    public function test_platform_admin_can_queue_the_automatic_download_fallback(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/fallback-automatico', [
                'dataset' => 'section_votes',
                'ano' => 2024,
                'uf' => 'ce',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $run = SincronizacaoTse::query()->firstOrFail();
        $this->assertNull($run->gabinete_id);
        $this->assertSame($admin->id, $run->solicitado_por_id);
        $this->assertSame('section_votes', $run->dataset);
        $this->assertSame(2024, $run->ano);
        $this->assertSame('pendente', $run->situacao);
        $this->assertSame(
            'https://cdn.tse.jus.br/estatistica/sead/odsele/votacao_secao/votacao_secao_2024_CE.zip',
            $run->fonte_url,
        );
        Queue::assertPushed(
            DownloadAndProcessTseDataset::class,
            fn (DownloadAndProcessTseDataset $job): bool => $job->runId === $run->id
                && $job->uf === 'CE',
        );
    }

    public function test_automatic_fallback_uses_the_current_year_for_a_yearless_dataset(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/fallback-automatico', [
                'dataset' => 'municipalities',
            ])
            ->assertSessionHasNoErrors();

        $run = SincronizacaoTse::query()->firstOrFail();
        $this->assertSame(now()->year, $run->ano);
        Queue::assertPushed(DownloadAndProcessTseDataset::class);
    }

    public function test_automatic_fallback_does_not_queue_a_duplicate_active_run(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'candidates',
            'ano' => 2024,
            'fonte_url' => 'https://example.test/consulta_cand_2024.zip',
            'situacao' => 'processando',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/fallback-automatico', [
                'dataset' => 'candidates',
                'ano' => 2024,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SincronizacaoTse::query()->count());
        Queue::assertNotPushed(DownloadAndProcessTseDataset::class);
    }

    public function test_automatic_fallback_duplicate_guard_does_not_cross_ufs(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $this->readySectionVotesOffice('SP', '99997', '99999999997');
        SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'section_votes',
            'ano' => 2024,
            'uf' => 'CE',
            'fonte_url' => 'https://example.test/votacao_secao_2024_CE.zip',
            'situacao' => 'processando',
            'iniciada_em' => now(),
        ]);

        // Um run ativo de CE não deveria travar o fallback de SP — cada UF
        // é uma sincronização independente.
        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/fallback-automatico', [
                'dataset' => 'section_votes',
                'ano' => 2024,
                'uf' => 'sp',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, SincronizacaoTse::query()->count());
        Queue::assertPushed(
            DownloadAndProcessTseDataset::class,
            fn (DownloadAndProcessTseDataset $job): bool => $job->uf === 'SP',
        );
    }

    public function test_upload_does_not_queue_a_duplicate_active_run(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'municipalities',
            'ano' => now()->year,
            'fonte_url' => 'https://example.test/municipio_tse_ibge.zip',
            'situacao' => 'processando',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'municipalities',
                'arquivo' => $this->fakeZip('municipio_tse_ibge.zip'),
            ])
            ->assertSessionHasErrors('arquivo');

        $this->assertSame(1, SincronizacaoTse::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_platform_admin_can_cancel_a_stuck_global_sync(): void
    {
        $admin = User::factory()->root()->create();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'municipalities',
            'ano' => now()->year,
            'fonte_url' => 'https://example.test/municipio_tse_ibge.zip',
            'situacao' => 'processando',
            'progresso_etapa' => 'lendo_arquivo',
            'progresso_percentual' => 82,
            'iniciada_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/sincronizacoes-tse-globais/{$run->id}/cancelar")
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $run->refresh();
        $this->assertSame('cancelada', $run->situacao);
        $this->assertNotNull($run->erro);
        $this->assertNull($run->progresso_etapa);
        $this->assertNull($run->progresso_percentual);
        $this->assertNotNull($run->concluida_em);

        // Cancelado, dá pra enviar de novo sem cair no bloqueio de duplicata.
        Queue::fake();
        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'municipalities',
                'arquivo' => $this->fakeZip('municipio_tse_ibge.zip'),
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, SincronizacaoTse::query()->count());
    }

    public function test_cancel_rejects_a_run_that_already_finished(): void
    {
        $admin = User::factory()->root()->create();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'municipalities',
            'ano' => now()->year,
            'fonte_url' => 'https://example.test/municipio_tse_ibge.zip',
            'situacao' => 'concluida',
            'iniciada_em' => now(),
            'concluida_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/sincronizacoes-tse-globais/{$run->id}/cancelar")
            ->assertSessionHasNoErrors();

        $this->assertSame('concluida', $run->fresh()->situacao);
    }

    public function test_cancel_does_not_reach_a_run_scoped_to_an_office(): void
    {
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'dataset' => 'pollingdata_polls',
            'ano' => 2026,
            'fonte_url' => 'https://example.test/pollingdata',
            'situacao' => 'processando',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/sincronizacoes-tse-globais/{$run->id}/cancelar")
            ->assertNotFound();

        $this->assertSame('processando', $run->fresh()->situacao);
    }

    public function test_year_is_required_only_for_datasets_published_by_year(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'candidates',
                'arquivo' => $this->fakeZip('municipio_tse_ibge.zip'),
            ])
            ->assertSessionHasErrors('ano');

        $this->assertSame(0, SincronizacaoTse::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_fields_that_do_not_belong_to_the_dataset_are_rejected(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'municipalities',
                'ano' => 2024,
                'uf' => 'CE',
                'arquivo' => $this->fakeZip('municipio_tse_ibge.zip'),
            ])
            ->assertSessionHasErrors(['ano', 'uf']);

        $this->assertDatabaseCount('sincronizacoes_tse', 0);
        Queue::assertNothingPushed();
    }

    public function test_polling_locations_accepts_the_single_national_csv_name_used_in_2024(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $archive = $this->uploadedZip(
            'eleitorado_local_votacao_2024.zip',
            'eleitorado_local_votacao_2024.csv',
            ['AA_ELEICAO', 'SG_UF', 'CD_MUNICIPIO', 'NR_ZONA', 'NR_SECAO', 'NR_LOCAL_VOTACAO', 'NM_LOCAL_VOTACAO'],
            ['2024', 'CE', '99998', '1', '1', '1', 'ESCOLA'],
        );

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'polling_locations',
                'ano' => 2024,
                'arquivo' => $archive,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sincronizacoes_tse', [
            'dataset' => 'polling_locations',
            'ano' => 2024,
            'situacao' => 'pendente',
        ]);
        Queue::assertPushed(ProcessUploadedTseDataset::class);
    }

    public function test_section_votes_rejects_a_csv_missing_columns_used_by_the_importer(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $archive = $this->uploadedZip(
            'votacao_secao_2024_CE.zip',
            'votacao_secao_2024_CE.csv',
            ['ANO_ELEICAO', 'SG_UF', 'CD_MUNICIPIO', 'SQ_CANDIDATO', 'QT_VOTOS'],
            ['2024', 'CE', '99998', '99999999998', '10'],
        );

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'section_votes',
                'ano' => 2024,
                'uf' => 'CE',
                'arquivo' => $archive,
            ])
            ->assertSessionHasErrors('arquivo');

        $this->assertDatabaseCount('sincronizacoes_tse', 0);
        Queue::assertNothingPushed();
    }

    public function test_section_votes_upload_requires_a_uf_and_threads_it_to_the_job(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'section_votes',
                'ano' => 2024,
                'arquivo' => $this->fakeZip('votacao_secao_2024_CE.zip'),
            ])
            ->assertSessionHasErrors('uf');

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'section_votes',
                'ano' => 2024,
                'uf' => 'ce',
                'arquivo' => $this->fakeZip('votacao_secao_2024_CE.zip'),
            ])
            ->assertSessionHasNoErrors();

        $run = SincronizacaoTse::query()->where('dataset', 'section_votes')->firstOrFail();
        Queue::assertPushed(ProcessUploadedTseDataset::class, fn (ProcessUploadedTseDataset $job): bool => $job->runId === $run->id && $job->uf === 'CE');
    }

    public function test_a_corrupted_zip_is_rejected_without_creating_a_run_or_queueing_a_job(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $corrupted = UploadedFile::fake()->createWithContent('municipio_tse_ibge.zip', 'isto não é um zip válido');

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'municipalities',
                'arquivo' => $corrupted,
            ])
            ->assertSessionHasErrors('arquivo');

        $this->assertSame(0, SincronizacaoTse::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_zip_from_another_dataset_is_rejected_before_queueing(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'candidates',
                'ano' => 2026,
                'arquivo' => $this->fakeZip('municipio_tse_ibge.zip'),
            ])
            ->assertSessionHasErrors('arquivo');

        $this->assertSame(0, SincronizacaoTse::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_section_votes_content_must_match_the_selected_year_and_uf(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $this->readySectionVotesOffice('SP', '99997', '99999999997');

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'section_votes',
                'ano' => 2022,
                'uf' => 'CE',
                'arquivo' => $this->fakeZip('votacao_secao_2024_CE.zip'),
            ])
            ->assertSessionHasErrors('dataset');

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'section_votes',
                'ano' => 2024,
                'uf' => 'SP',
                'arquivo' => $this->fakeZip('votacao_secao_2024_CE.zip'),
            ])
            ->assertSessionHasErrors('arquivo');

        $this->assertSame(0, SincronizacaoTse::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_browser_upload_limit_is_enforced_by_the_request(): void
    {
        Queue::fake();
        config(['services.tse.manual_upload_max_megabytes' => 1]);
        $admin = User::factory()->root()->create();
        $oversized = UploadedFile::fake()->create('consulta_cand_2026.zip', 1025, 'application/zip');

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'candidates',
                'ano' => 2026,
                'arquivo' => $oversized,
            ])
            ->assertSessionHasErrors('arquivo');

        $this->assertSame(0, SincronizacaoTse::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_non_admin_user_is_forbidden(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/admin/sincronizacoes-tse-globais/upload', [
                'dataset' => 'municipalities',
                'arquivo' => $this->fakeZip('municipio_tse_ibge.zip'),
            ])
            ->assertForbidden();

        $this->assertSame(0, SincronizacaoTse::query()->count());
    }

    public function test_the_job_processes_the_archive_and_completes_the_run(): void
    {
        $office = Gabinete::factory()->create(['municipio' => 'Cruz', 'estado' => 'CE']);
        $archive = $this->municipalitiesArchive();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'municipalities',
            'ano' => 2026,
            'fonte_url' => 'upload-manual://municipio_tse_ibge.zip',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        (new ProcessUploadedTseDataset($run->id, $archive))
            ->handle(app(TsePoliticalDataSyncService::class));

        $run->refresh();
        $this->assertSame('concluida', $run->situacao);
        $this->assertGreaterThan(0, $run->registros_processados);
        $this->assertNotNull($office->fresh()->municipio_eleitoral_id);
        $this->assertFileDoesNotExist($archive);
        // `municipalities` já cobre o Brasil inteiro independente de
        // gabinete cadastrado — só `section_votes` precisa reter o ZIP pra
        // reprocessar um gabinete novo depois, então este aqui é só
        // descartado normalmente, não retido.
        $this->assertNull($run->arquivo_retido_path);
    }

    public function test_only_section_votes_retains_the_uploaded_archive(): void
    {
        [, $election] = $this->sectionVotesFixtures();
        $firstArchive = $this->sectionVotesArchive();
        $firstRun = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'section_votes',
            'ano' => $election->ano,
            'uf' => 'CE',
            'fonte_url' => 'upload-manual://votacao_secao_2024_CE.zip',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);
        (new ProcessUploadedTseDataset($firstRun->id, $firstArchive, 'CE'))
            ->handle(app(TsePoliticalDataSyncService::class));
        $firstRetainedPath = $firstRun->refresh()->arquivo_retido_path;
        $this->assertNotNull($firstRetainedPath);
        $this->assertNotNull($firstRun->checksum_sha256);
        $this->assertFileExists($firstRetainedPath);

        $secondArchive = $this->sectionVotesArchive();
        $secondRun = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'section_votes',
            'ano' => $election->ano,
            'uf' => 'CE',
            'fonte_url' => 'upload-manual://votacao_secao_2024_CE.zip',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);
        (new ProcessUploadedTseDataset($secondRun->id, $secondArchive, 'CE'))
            ->handle(app(TsePoliticalDataSyncService::class));

        // Mesma combinação dataset/ano/UF ocupa sempre o mesmo nome de
        // arquivo — o primeiro run perde a própria referência (é isso que
        // prova que foi substituído), mesmo que o caminho em si continue
        // existindo, agora com o conteúdo do segundo.
        $this->assertNull($firstRun->fresh()->arquivo_retido_path);
        $this->assertSame($firstRetainedPath, $secondRun->fresh()->arquivo_retido_path);
        $this->assertFileExists($secondRun->fresh()->arquivo_retido_path);
    }

    public function test_the_job_skips_and_cleans_up_when_the_run_was_already_cancelled(): void
    {
        $archive = $this->municipalitiesArchive();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'municipalities',
            'ano' => 2026,
            'fonte_url' => 'upload-manual://municipio_tse_ibge.zip',
            'situacao' => 'cancelada',
            'iniciada_em' => now(),
            'concluida_em' => now(),
        ]);

        (new ProcessUploadedTseDataset($run->id, $archive))
            ->handle(app(TsePoliticalDataSyncService::class));

        $this->assertSame('cancelada', $run->fresh()->situacao);
        $this->assertFileDoesNotExist($archive);
        $this->assertDatabaseMissing('municipios_eleitorais', ['codigo_tse' => '15890']);
    }

    /**
     * @return array{0: Gabinete, 1: Eleicao}
     */
    private function sectionVotesFixtures(): array
    {
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

        return [$office, $election];
    }

    private function sectionVotesArchive(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'govnexgab-upload-');
        $this->assertNotFalse($path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $header = [
            'DT_GERACAO', 'HH_GERACAO', 'ANO_ELEICAO', 'CD_TIPO_ELEICAO',
            'NM_TIPO_ELEICAO', 'NR_TURNO', 'CD_ELEICAO', 'DS_ELEICAO',
            'DT_ELEICAO', 'TP_ABRANGENCIA', 'SG_UF', 'SG_UE', 'NM_UE',
            'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'NR_SECAO',
            'CD_CARGO', 'DS_CARGO', 'NR_VOTAVEL', 'NM_VOTAVEL', 'QT_VOTOS',
            'NR_LOCAL_VOTACAO', 'SQ_CANDIDATO', 'NM_LOCAL_VOTACAO',
            'DS_LOCAL_VOTACAO_ENDERECO',
        ];
        $row = ['28/10/2024', '11:46:22', '2024', '2', 'Eleição Ordinária', '1', '619', 'Eleições Municipais 2024', '06/10/2024', 'M', 'CE', '15890', 'CRUZ', '15890', 'CRUZ', '30', '0011', '13', 'Vereador', '11555', 'MARCOS SILVEIRA', '500', '0001', '60001945113', 'ESCOLA MUNICIPAL', 'RUA A, 10'];
        $contents = collect([$header, $row])
            ->map(fn (array $values): string => implode(';', array_map(
                fn (string $value): string => '"'.str_replace('"', '""', $value).'"',
                $values,
            )))
            ->implode("\r\n");
        $this->assertTrue($zip->addFromString(
            'votacao_secao_2024_CE.csv',
            mb_convert_encoding($contents, 'Windows-1252', 'UTF-8'),
        ));
        $this->assertTrue($zip->close());

        return $path;
    }

    private function municipalitiesArchive(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'govnexgab-upload-');
        $this->assertNotFalse($path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $header = ['DT_GERACAO', 'SG_UF', 'CD_MUNICIPIO_TSE', 'NM_MUNICIPIO_TSE', 'CD_MUNICIPIO_IBGE', 'NM_MUNICIPIO_IBGE'];
        $row = ['26/07/2026', 'CE', '15890', 'Cruz', '2304251', 'Cruz'];
        $contents = implode(';', $header)."\r\n".implode(';', $row)."\r\n";
        $this->assertTrue($zip->addFromString('municipio_tse_ibge.csv', $contents));
        $this->assertTrue($zip->close());

        return $path;
    }

    private function fakeZip(string $filename): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'govnexgab-upload-');
        $this->assertNotFalse($path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        if (str_starts_with($filename, 'votacao_secao_')) {
            $header = [
                'ANO_ELEICAO', 'SG_UF', 'CD_MUNICIPIO', 'NR_TURNO',
                'NR_ZONA', 'NR_SECAO', 'DS_CARGO', 'SQ_CANDIDATO', 'QT_VOTOS',
            ];
            $row = ['2024', 'CE', '15890', '1', '30', '0011', 'Vereador', '60001945113', '500'];
            $entryName = 'votacao_secao_2024_CE.csv';
        } else {
            $header = [
                'SG_UF', 'CD_MUNICIPIO_TSE', 'NM_MUNICIPIO_TSE',
                'CD_MUNICIPIO_IBGE',
            ];
            $row = ['CE', '15890', 'Cruz', '2304251'];
            $entryName = 'municipio_tse_ibge.csv';
        }

        $contents = implode(';', $header)."\r\n".implode(';', $row)."\r\n";
        $this->assertTrue($zip->addFromString($entryName, $contents));
        $this->assertTrue($zip->close());

        return new UploadedFile($path, $filename, 'application/zip', null, true);
    }

    /** @param list<string> $header @param list<string> $row */
    private function uploadedZip(string $filename, string $entryName, array $header, array $row): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'govnexgab-upload-');
        $this->assertNotFalse($path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $this->assertTrue($zip->addFromString(
            $entryName,
            implode(';', $header)."\r\n".implode(';', $row)."\r\n",
        ));
        $this->assertTrue($zip->close());

        return new UploadedFile($path, $filename, 'application/zip', null, true);
    }

    private function readySectionVotesOffice(string $uf, string $municipalityCode, string $candidateSequence): Gabinete
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => $municipalityCode,
            'nome' => "Município {$uf}",
            'uf' => $uf,
        ]);
        $election = Eleicao::query()->where('ano', 2024)->where('tipo', 'municipal')->firstOrFail();
        $candidate = CandidatoPolitico::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => $candidateSequence,
            'abrangencia' => 'municipal',
            'municipio_eleitoral_id' => $municipality->id,
            'uf' => $uf,
            'cargo' => 'Vereador',
            'nome' => "TITULAR {$uf}",
            'nome_urna' => "TITULAR {$uf}",
        ]);

        return Gabinete::factory()->create([
            'estado' => $uf,
            'municipio' => $municipality->nome,
            'municipio_eleitoral_id' => $municipality->id,
            'candidato_titular_id' => $candidate->id,
        ]);
    }
}

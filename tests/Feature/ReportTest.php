<?php

namespace Tests\Feature;

use App\Enums\DemandOrigin;
use App\Enums\DemandPriority;
use App\Enums\DemandStatus;
use App\Enums\GabineteModule;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Jobs\GenerateReportExport;
use App\Models\Demanda;
use App\Models\Gabinete;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Reports\ReportExportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;
use ZipArchive;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_reports_are_available_to_office_managers_but_not_advisors(): void
    {
        $office = Gabinete::factory()->create();
        $councilor = User::factory()->councilor()->forGabinete($office)->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();

        $this->actingAs($councilor)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('reports/index'));

        $this->actingAs($advisor)
            ->get(route('reports.index'))
            ->assertForbidden();
    }

    public function test_report_metrics_filters_and_rows_are_isolated_by_office(): void
    {
        Carbon::setTestNow('2026-07-24 12:00:00');
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->chiefOfStaff()->forGabinete($office)->create();

        Demanda::factory()->forGabinete($office)->create([
            'titulo' => 'Resolvida no período',
            'status' => DemandStatus::Resolved,
            'prioridade' => DemandPriority::High,
            'origem' => DemandOrigin::Phone,
            'aberta_em' => now()->subDays(5),
            'concluida_em' => now()->subDays(3),
        ]);
        Demanda::factory()->forGabinete($office)->create([
            'titulo' => 'Atrasada no período',
            'status' => DemandStatus::InProgress,
            'prioridade' => DemandPriority::Urgent,
            'origem' => DemandOrigin::WhatsApp,
            'aberta_em' => now()->subDays(4),
            'prazo' => now()->subDay(),
        ]);
        Demanda::factory()->forGabinete($office)->create([
            'titulo' => 'Fora do período',
            'status' => DemandStatus::New,
            'aberta_em' => now()->subDays(120),
        ]);
        Demanda::factory()->forGabinete($otherOffice)->create([
            'titulo' => 'Outro gabinete',
            'status' => DemandStatus::InProgress,
            'aberta_em' => now()->subDays(2),
            'prazo' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->get(route('reports.index', [
                'inicio' => now()->subDays(30)->toDateString(),
                'fim' => now()->toDateString(),
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total', 2)
                ->where('summary.open', 1)
                ->where('summary.resolved', 1)
                ->where('summary.overdue', 1)
                ->where('summary.resolution_rate', 50)
                ->where('summary.average_resolution_hours', 48)
                ->has('demands.data', 2)
                ->has('charts.status', 5)
                ->has('charts.priority', 4)
                ->has('charts.origin', 7));

        $this->actingAs($user)
            ->get(route('reports.index', [
                'inicio' => now()->subDays(30)->toDateString(),
                'fim' => now()->toDateString(),
                'atrasadas' => 1,
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total', 1)
                ->where('demands.data.0.title', 'Atrasada no período'));
    }

    public function test_export_request_is_queued_with_normalized_filters(): void
    {
        Queue::fake();
        $office = Gabinete::factory()->create();
        $user = User::factory()->councilor()->forGabinete($office)->create();

        $this->actingAs($user)
            ->post(route('reports.exports.store'), [
                'formato' => 'pdf',
                'inicio' => '2026-07-01',
                'fim' => '2026-07-24',
                'atrasadas' => true,
            ])
            ->assertRedirect();

        $export = ReportExport::withoutGlobalScopes()->sole();
        $this->assertSame($office->id, $export->gabinete_id);
        $this->assertSame(ReportExportFormat::Pdf, $export->formato);
        $this->assertSame(ReportExportStatus::Pending, $export->status);
        $this->assertTrue($export->filtros['atrasadas']);
        Queue::assertPushed(
            GenerateReportExport::class,
            fn (GenerateReportExport $job): bool => $job->exportId === $export->id,
        );
    }

    public function test_job_generates_valid_pdf_and_xlsx_files(): void
    {
        Carbon::setTestNow('2026-07-24 12:00:00');
        Storage::fake('local');
        Storage::fake('public');
        $logoPath = UploadedFile::fake()->image('logo.png', 240, 120)->store('gabinetes/teste', 'public');
        $office = Gabinete::factory()->create([
            'cabecalho_relatorios' => 'Gabinete de Teste',
            'cor_principal' => '#0F766E',
            'logo_path' => $logoPath,
        ]);
        $user = User::factory()->councilor()->forGabinete($office)->create();
        Demanda::factory()->forGabinete($office)->status(DemandStatus::Resolved)->create([
            'titulo' => 'Solicitação para exportação',
            'aberta_em' => now()->subDays(2),
            'concluida_em' => now(),
        ]);

        foreach ([ReportExportFormat::Pdf, ReportExportFormat::Xlsx] as $format) {
            $export = ReportExport::forceCreate([
                'gabinete_id' => $office->id,
                'solicitado_por_id' => $user->id,
                'formato' => $format,
                'filtros' => [
                    'inicio' => '2026-07-01',
                    'fim' => '2026-07-24',
                    'status' => null,
                    'prioridade' => null,
                    'origem' => null,
                    'categoria_id' => null,
                    'bairro_id' => null,
                    'responsavel_id' => null,
                    'atrasadas' => false,
                ],
                'status' => ReportExportStatus::Pending,
                'disk' => 'local',
            ]);

            (new GenerateReportExport($export->id))->handle(
                app(ReportExportGenerator::class),
                app(GabineteModuleManager::class),
            );
            $export->refresh();

            $this->assertSame(ReportExportStatus::Completed, $export->status);
            $this->assertNotNull($export->caminho);
            Storage::disk('local')->assertExists($export->caminho);
            $absolutePath = Storage::disk('local')->path($export->caminho);

            if ($format === ReportExportFormat::Pdf) {
                $this->assertStringStartsWith('%PDF-', (string) file_get_contents($absolutePath, false, null, 0, 5));
            } else {
                $zip = new ZipArchive;
                $this->assertTrue($zip->open($absolutePath) === true);
                $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
                $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
                $this->assertNotFalse($zip->locateName('xl/drawings/drawing1.xml'));
                $this->assertNotFalse($zip->locateName('xl/media/image1.png'));
                $zip->close();

                $reader = new Reader;
                $reader->open($absolutePath);
                $sheets = [];
                $firstRows = [];

                foreach ($reader->getSheetIterator() as $sheet) {
                    $sheets[] = $sheet->getName();

                    foreach ($sheet->getRowIterator() as $row) {
                        $firstRows[$sheet->getName()] = $row->toArray();
                        break;
                    }
                }

                $reader->close();

                $this->assertSame(['Resumo', 'Demandas', 'Produtividade', 'Encaminhamentos'], $sheets);
                $this->assertSame('Gabinete de Teste', $firstRows['Resumo'][0]);
                $this->assertSame('Protocolo', $firstRows['Demandas'][0]);
                $this->assertSame('Responsável', $firstRows['Produtividade'][0]);
                $this->assertSame('Protocolo', $firstRows['Encaminhamentos'][0]);
            }
        }
    }

    public function test_export_download_is_private_and_office_scoped(): void
    {
        Storage::fake('local');
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $owner = User::factory()->councilor()->forGabinete($office)->create();
        $outsider = User::factory()->councilor()->forGabinete($otherOffice)->create();
        Storage::disk('local')->put('reports/1/report.pdf', '%PDF-test');

        $export = ReportExport::forceCreate([
            'gabinete_id' => $office->id,
            'solicitado_por_id' => $owner->id,
            'formato' => ReportExportFormat::Pdf,
            'filtros' => [],
            'status' => ReportExportStatus::Completed,
            'disk' => 'local',
            'caminho' => 'reports/1/report.pdf',
            'nome_arquivo' => 'relatorio.pdf',
            'mime_type' => 'application/pdf',
            'tamanho' => 9,
            'concluido_em' => now(),
            'expira_em' => now()->addDay(),
        ]);

        $this->actingAs($owner)
            ->get(route('reports.exports.download', $export))
            ->assertOk()
            ->assertDownload('relatorio.pdf');

        $this->actingAs($outsider)
            ->get(route('reports.exports.download', $export))
            ->assertNotFound();
    }

    public function test_pending_export_is_cancelled_when_reports_are_disabled(): void
    {
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $modules = app(GabineteModuleManager::class);
        $modules->sync($office, [GabineteModule::Relationship->value], $admin);
        $export = ReportExport::forceCreate([
            'gabinete_id' => $office->id,
            'solicitado_por_id' => $user->id,
            'formato' => ReportExportFormat::Pdf,
            'filtros' => [],
            'status' => ReportExportStatus::Pending,
            'disk' => 'local',
        ]);
        $generator = Mockery::mock(ReportExportGenerator::class);
        $generator->shouldNotReceive('generate');

        (new GenerateReportExport($export->id))->handle($generator, $modules);

        $this->assertSame(ReportExportStatus::Cancelled, $export->refresh()->status);
        $this->assertNotNull($export->concluido_em);
    }

    public function test_report_rejects_filters_from_another_office(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $foreignMember = User::factory()->forGabinete($otherOffice)->create();

        $this->actingAs($user)
            ->get(route('reports.index', [
                'inicio' => '2026-07-01',
                'fim' => '2026-07-24',
                'responsavel_id' => $foreignMember->id,
            ]))
            ->assertSessionHasErrors('responsavel_id');
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Enums\ElectionType;
use App\Enums\GabineteModule;
use App\Enums\GabineteStatus;
use App\Enums\GabineteType;
use App\Enums\UserRole;
use App\Jobs\PrepareOfficePoliticalData;
use App\Jobs\SyncOfficeSectionVotesFromGovnexApi;
use App\Models\CandidatoPolitico;
use App\Models\Demanda;
use App\Models\Eleicao;
use App\Models\EleitoradoMunicipioSnapshot;
use App\Models\Entidade;
use App\Models\Gabinete;
use App\Models\MunicipioEleitoral;
use App\Models\SincronizacaoTse;
use App\Models\User;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\Polls\PollingDataService;
use App\Services\Politics\Polls\ResultResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([
                ['id' => 2304400, 'nome' => 'Fortaleza'],
            ]),
        ]);
    }

    public function test_platform_admin_sees_global_metrics_and_office_usage(): void
    {
        $admin = User::factory()->root()->create();
        $active = Gabinete::factory()->create(['nome' => 'Gabinete Ativo']);
        Gabinete::factory()->suspended()->create();
        $tenantUser = User::factory()->forGabinete($active)->create();
        Demanda::factory()->forGabinete($active, creator: $tenantUser)->count(2)->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/dashboard')
                ->where('summary.offices', 2)
                ->where('summary.active_offices', 1)
                ->where('summary.suspended_offices', 1)
                ->where('summary.active_users', 1)
                ->where('summary.demands', 2)
                ->has('usage', 2)
                ->where('usage.0.name', 'Gabinete Ativo'));
    }

    public function test_only_platform_admin_can_open_office_management(): void
    {
        $office = Gabinete::factory()->create();
        $tenantUser = User::factory()->forGabinete($office)->councilor()->create();

        $this->actingAs($tenantUser)
            ->get(route('admin.offices.index'))
            ->assertForbidden();
    }

    public function test_platform_admin_can_list_and_filter_offices(): void
    {
        $admin = User::factory()->root()->create();
        Gabinete::factory()->create(['nome' => 'Gabinete Aurora', 'estado' => 'CE']);
        Gabinete::factory()->suspended()->create(['nome' => 'Gabinete Horizonte', 'estado' => 'SP']);

        $this->actingAs($admin)
            ->get(route('admin.offices.index', ['q' => 'Aurora', 'estado' => 'CE']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/offices/index')
                ->where('filters.q', 'Aurora')
                ->has('offices.data', 1)
                ->where('offices.data.0.name', 'Gabinete Aurora')
                ->where('offices.data.0.demands_count', 0)
                ->where('offices.data.0.citizens_count', 0));
    }

    public function test_office_index_exposes_a_political_data_checklist_per_office(): void
    {
        $admin = User::factory()->root()->create();
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
        EleitoradoMunicipioSnapshot::query()->create([
            'municipio_eleitoral_id' => $municipality->id,
            'ano_referencia' => 2026,
            'data_referencia' => now()->toDateString(),
            'eleitores_aptos' => 12345,
            'fonte_url' => 'https://cdn.tse.jus.br/perfil_eleitorado_2026.zip',
            'fonte_gerada_em' => now(),
        ]);
        Gabinete::factory()->create([
            'nome' => 'Gabinete Checklist',
            'municipio' => 'Cruz',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $municipality->id,
            'numero_eleitoral' => '11555',
            'candidato_titular_id' => $titular->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.offices.index', ['q' => 'Gabinete Checklist']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/offices/index')
                ->has('offices.data', 1)
                ->where('offices.data.0.electorate_count', 12345)
                ->where(
                    'offices.data.0.political_data_checklist',
                    fn (Collection $checklist): bool => $checklist
                        ->firstWhere('key', 'candidates')['available'] === true
                        && $checklist
                            ->firstWhere('key', 'turnout')['available'] === false
                        && $checklist
                            ->firstWhere('key', 'section_votes')['available'] === false
                        && $checklist
                            ->firstWhere('key', 'section_votes')['note'] === null,
                ));
    }

    public function test_platform_admin_creates_office_and_responsible_account(): void
    {
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.offices.index'));

        $office = Gabinete::withoutGlobalScopes()->where('nome', 'Gabinete Cidadão')->firstOrFail();
        $this->assertSame(GabineteStatus::Active, $office->status);
        $this->assertSame('gabinete-cidadao', $office->slug);
        $this->assertSame('85999990000', $office->telefone);
        $this->assertSame('12345', $office->numero_eleitoral);

        $responsible = User::where('email', 'responsavel@gabinete.test')->firstOrFail();
        $this->assertSame($office->id, $responsible->gabinete_id);
        $this->assertSame(UserRole::Councilor, $responsible->role);
        $this->assertTrue($responsible->is_active);
        $this->assertTrue(Hash::check('Senha!Segura2026', $responsible->password));
    }

    public function test_creating_an_office_does_not_start_tse_downloads(): void
    {
        Queue::fake();
        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([
                ['id' => 2304400, 'nome' => 'Fortaleza'],
            ]),
        ]);
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $this->payload([
                'sincronizar_tse' => true,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.offices.index'));

        $office = Gabinete::withoutGlobalScopes()
            ->where('nome', 'Gabinete Cidadão')
            ->firstOrFail();

        $this->assertDatabaseCount('sincronizacoes_tse', 0);
        Queue::assertNotPushed(PrepareOfficePoliticalData::class);
    }

    public function test_creating_an_office_with_politics_active_queues_a_retained_archive_sync(): void
    {
        Queue::fake();
        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([
                ['id' => 2304400, 'nome' => 'Fortaleza'],
            ]),
        ]);
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $office = Gabinete::withoutGlobalScopes()->where('nome', 'Gabinete Cidadão')->firstOrFail();
        Queue::assertPushed(
            SyncOfficeSectionVotesFromGovnexApi::class,
            fn (SyncOfficeSectionVotesFromGovnexApi $job): bool => $job->officeId === $office->id,
        );
    }

    public function test_platform_admin_can_request_pollingdata_sync(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.offices.political-sync.store', $office), [
                'tasks' => ['pollingdata_polls'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SincronizacaoTse::query()->count());
        $this->assertDatabaseHas('sincronizacoes_tse', [
            'gabinete_id' => $office->id,
            'dataset' => 'pollingdata_polls',
            'ano' => 2026,
        ]);
        Queue::assertPushed(PrepareOfficePoliticalData::class, 1);
    }

    public function test_platform_admin_can_view_the_political_sync_page(): void
    {
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'dataset' => 'pollingdata_polls',
            'ano' => 2026,
            'fonte_url' => 'https://flex.pollingdata.com.br/',
            'situacao' => 'concluida',
            'registros_processados' => 12,
            'iniciada_em' => now(),
            'concluida_em' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.political-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/political-sync/index')
                ->where('politicsAvailable', true)
                ->where('pollingData.year', 2026)
                ->has('pollingData.offices', 1)
                ->where('pollingData.offices.0.id', $office->id)
                ->where('pollingData.offices.0.name', $office->nome)
                ->where('pollingData.offices.0.latest_sync.dataset', 'pollingdata_polls')
                ->where('pollingData.offices.0.latest_sync.status', 'concluida'));
    }

    public function test_political_sync_page_lists_only_offices_with_politics_active(): void
    {
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        app(GabineteModuleManager::class)->sync(
            $office,
            array_values(array_diff(
                array_map(fn (GabineteModule $module): string => $module->value, GabineteModule::cases()),
                [GabineteModule::Politics->value],
            )),
            $admin,
        );

        $this->actingAs($admin)
            ->get(route('admin.political-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/political-sync/index')
                ->where('politicsAvailable', false)
                ->has('pollingData.offices', 0));
    }

    public function test_tenant_user_cannot_view_the_political_sync_page(): void
    {
        $office = Gabinete::factory()->create();
        $tenantUser = User::factory()->forGabinete($office)->councilor()->create();

        $this->actingAs($tenantUser)
            ->get(route('admin.political-sync.index'))
            ->assertForbidden();
    }

    public function test_requeues_an_active_sync_without_creating_a_duplicate_run(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'dataset' => 'pollingdata_polls',
            'ano' => now()->year,
            'fonte_url' => 'https://www.pollingdata.com.br/',
            'situacao' => 'processando',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.political-sync.store', $office), [
                'tasks' => ['pollingdata_polls'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SincronizacaoTse::query()->count());
        Queue::assertPushed(PrepareOfficePoliticalData::class, function (
            PrepareOfficePoliticalData $job,
        ): bool {
            return count($job->runIds) === 1
                && $job->connection === 'database'
                && $job->queue === 'tse';
        });
    }

    public function test_replaces_an_orphaned_sync_and_queues_a_new_attempt(): void
    {
        Queue::fake();
        config(['services.tse.stale_after_minutes' => 45]);
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $staleRun = SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'dataset' => 'pollingdata_polls',
            'ano' => now()->year,
            'fonte_url' => 'https://www.pollingdata.com.br/',
            'situacao' => 'processando',
            'iniciada_em' => now()->subHours(2),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.political-sync.store', $office), [
                'tasks' => ['pollingdata_polls'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('falhou', $staleRun->fresh()->situacao);
        $this->assertNotNull($staleRun->fresh()->concluida_em);
        $this->assertDatabaseHas('sincronizacoes_tse', [
            'gabinete_id' => $office->id,
            'dataset' => 'pollingdata_polls',
            'ano' => now()->year,
            'situacao' => 'pendente',
        ]);
        $this->assertSame(2, SincronizacaoTse::query()->count());
        Queue::assertPushed(PrepareOfficePoliticalData::class, 1);
    }

    public function test_platform_admin_can_manually_restart_a_stuck_sync(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $stuckRun = SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'dataset' => 'pollingdata_polls',
            'ano' => now()->year,
            'fonte_url' => 'https://www.pollingdata.com.br/',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.political-sync.restart', [$office, $stuckRun]))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', [
                'type' => 'success',
                'message' => 'Sincronização reiniciada. Uma nova execução foi adicionada à fila.',
            ]);

        $this->assertSame('cancelada', $stuckRun->fresh()->situacao);
        $this->assertNotNull($stuckRun->fresh()->concluida_em);
        $this->assertDatabaseHas('sincronizacoes_tse', [
            'gabinete_id' => $office->id,
            'dataset' => 'pollingdata_polls',
            'ano' => now()->year,
            'situacao' => 'pendente',
        ]);
        $this->assertSame(2, SincronizacaoTse::query()->count());
        Queue::assertPushed(PrepareOfficePoliticalData::class, function (
            PrepareOfficePoliticalData $job,
        ) use ($stuckRun): bool {
            return count($job->runIds) === 1
                && $job->runIds[0] !== $stuckRun->id;
        });
    }

    public function test_cannot_restart_a_completed_sync(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $completedRun = SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'dataset' => 'pollingdata_polls',
            'ano' => now()->year,
            'fonte_url' => 'https://www.pollingdata.com.br/',
            'situacao' => 'concluida',
            'iniciada_em' => now(),
            'concluida_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.political-sync.restart', [$office, $completedRun]))
            ->assertInertiaFlash('toast', [
                'type' => 'error',
                'message' => 'Só é possível reiniciar sincronizações pendentes.',
            ]);

        $this->assertSame('concluida', $completedRun->fresh()->situacao);
        $this->assertSame(1, SincronizacaoTse::query()->count());
        Queue::assertNotPushed(PrepareOfficePoliticalData::class);
    }

    public function test_cannot_restart_a_sync_that_is_already_processing(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $processingRun = SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'dataset' => 'pollingdata_polls',
            'ano' => now()->year,
            'fonte_url' => 'https://www.pollingdata.com.br/',
            'situacao' => 'processando',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.political-sync.restart', [$office, $processingRun]))
            ->assertInertiaFlash('toast', [
                'type' => 'error',
                'message' => 'A sincronização já está em processamento. Aguarde a conclusão.',
            ]);

        $this->assertSame('processando', $processingRun->fresh()->situacao);
        $this->assertSame(1, SincronizacaoTse::query()->count());
        Queue::assertNotPushed(PrepareOfficePoliticalData::class);
    }

    public function test_cannot_restart_a_sync_belonging_to_another_office(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => $otherOffice->id,
            'dataset' => 'municipalities',
            'ano' => now()->year,
            'fonte_url' => 'https://cdn.tse.jus.br/municipio_tse_ibge/municipio_tse_ibge.zip',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.political-sync.restart', [$office, $run]))
            ->assertInertiaFlash('toast', [
                'type' => 'error',
                'message' => 'Esta sincronização não pertence a este gabinete.',
            ]);

        $this->assertSame('pendente', $run->fresh()->situacao);
        Queue::assertNotPushed(PrepareOfficePoliticalData::class);
    }

    public function test_tenant_user_cannot_restart_a_sync(): void
    {
        $office = Gabinete::factory()->create();
        $tenantUser = User::factory()->forGabinete($office)->councilor()->create();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'dataset' => 'municipalities',
            'ano' => now()->year,
            'fonte_url' => 'https://cdn.tse.jus.br/municipio_tse_ibge/municipio_tse_ibge.zip',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($tenantUser)
            ->post(route('admin.offices.political-sync.restart', [$office, $run]))
            ->assertForbidden();
    }

    public function test_platform_admin_can_cancel_a_pending_sync(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'dataset' => 'municipalities',
            'ano' => now()->year,
            'fonte_url' => 'https://cdn.tse.jus.br/municipio_tse_ibge/municipio_tse_ibge.zip',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.political-sync.cancel', [$office, $run]))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', [
                'type' => 'success',
                'message' => 'Sincronização cancelada.',
            ]);

        $run->refresh();
        $this->assertSame('cancelada', $run->situacao);
        $this->assertNotNull($run->concluida_em);
        $this->assertSame(1, SincronizacaoTse::query()->count());
        Queue::assertNotPushed(PrepareOfficePoliticalData::class);
    }

    public function test_cannot_cancel_a_completed_sync(): void
    {
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $completedRun = SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'dataset' => 'municipalities',
            'ano' => now()->year,
            'fonte_url' => 'https://cdn.tse.jus.br/municipio_tse_ibge/municipio_tse_ibge.zip',
            'situacao' => 'concluida',
            'iniciada_em' => now(),
            'concluida_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.political-sync.cancel', [$office, $completedRun]))
            ->assertInertiaFlash('toast', [
                'type' => 'error',
                'message' => 'Só é possível cancelar sincronizações pendentes.',
            ]);

        $this->assertSame('concluida', $completedRun->fresh()->situacao);
    }

    public function test_cannot_cancel_a_sync_that_is_already_processing(): void
    {
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $processingRun = SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'dataset' => 'pollingdata_polls',
            'ano' => now()->year,
            'fonte_url' => 'https://www.pollingdata.com.br/',
            'situacao' => 'processando',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.political-sync.cancel', [$office, $processingRun]))
            ->assertInertiaFlash('toast', [
                'type' => 'error',
                'message' => 'A sincronização já está em processamento. Aguarde a conclusão.',
            ]);

        $this->assertSame('processando', $processingRun->fresh()->situacao);
    }

    public function test_cannot_cancel_a_sync_belonging_to_another_office(): void
    {
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => $otherOffice->id,
            'dataset' => 'municipalities',
            'ano' => now()->year,
            'fonte_url' => 'https://cdn.tse.jus.br/municipio_tse_ibge/municipio_tse_ibge.zip',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.political-sync.cancel', [$office, $run]))
            ->assertInertiaFlash('toast', [
                'type' => 'error',
                'message' => 'Esta sincronização não pertence a este gabinete.',
            ]);

        $this->assertSame('pendente', $run->fresh()->situacao);
    }

    public function test_tenant_user_cannot_cancel_a_sync(): void
    {
        $office = Gabinete::factory()->create();
        $tenantUser = User::factory()->forGabinete($office)->councilor()->create();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'dataset' => 'municipalities',
            'ano' => now()->year,
            'fonte_url' => 'https://cdn.tse.jus.br/municipio_tse_ibge/municipio_tse_ibge.zip',
            'situacao' => 'pendente',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($tenantUser)
            ->post(route('admin.offices.political-sync.cancel', [$office, $run]))
            ->assertForbidden();
    }

    public function test_a_queued_job_does_not_resurrect_a_cancelled_sync(): void
    {
        $office = Gabinete::factory()->create();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => $office->id,
            'dataset' => 'pollingdata_polls',
            'ano' => now()->year,
            'fonte_url' => 'https://www.pollingdata.com.br/',
            'situacao' => 'cancelada',
            'erro' => 'Cancelada manualmente por Administrador da Plataforma.',
            'iniciada_em' => now(),
            'concluida_em' => now(),
        ]);

        app(PrepareOfficePoliticalData::class, ['runIds' => [$run->id]])->handle(
            app(PollingDataService::class),
            app(ResultResolver::class),
            app(GabineteModuleManager::class),
        );

        $this->assertSame('cancelada', $run->fresh()->situacao);
    }

    public function test_tasks_without_a_past_election_are_skipped_and_reported(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        Eleicao::query()->where('tipo', ElectionType::General)->delete();

        $this->actingAs($admin)
            ->post(route('admin.offices.political-sync.store', $office), [
                'tasks' => ['pollingdata_polls'],
            ])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', [
                'type' => 'error',
                'message' => 'Nenhuma sincronização pôde ser preparada. Não foi possível preparar: Pesquisas de Presidente (PollingData) (nenhuma eleição anterior cadastrada).',
            ]);

        $this->assertDatabaseCount('sincronizacoes_tse', 0);
        Queue::assertNotPushed(PrepareOfficePoliticalData::class);
    }

    public function test_political_sync_page_exposes_global_tse_syncs_not_tied_to_an_office(): void
    {
        $admin = User::factory()->root()->create();
        SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'polling_locations',
            'ano' => 2024,
            'fonte_url' => 'https://cdn.tse.jus.br/eleitorado_locais_votacao/eleitorado_local_votacao_2024.zip',
            'situacao' => 'falhou',
            'erro' => 'O TSE retornou um arquivo vazio.',
            'registros_processados' => 0,
            'iniciada_em' => now(),
            'concluida_em' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.political-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/political-sync/index')
                ->has('globalSyncs', 1)
                ->where('politicsAvailable', false)
                ->where('globalSyncs.0.dataset', 'polling_locations')
                ->where('globalSyncs.0.status', 'falhou'));
    }

    public function test_political_sync_page_exposes_the_govnex_api_catalog_when_politics_is_active(): void
    {
        $admin = User::factory()->root()->create();
        Gabinete::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.political-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/political-sync/index')
                ->where('politicsAvailable', true)
                ->where('datasetSlugPatterns.section_votes', 'votacao-secao-{ano}-{uf}')
                ->where('datasetSlugPatterns.municipalities', 'municipio-tse-ibge')
                ->where('elections.0.year', 2026)
                ->where('elections.0.type', 'geral'));
    }

    public function test_responsible_email_must_be_unique(): void
    {
        $admin = User::factory()->root()->create();
        User::factory()->create(['email' => 'responsavel@gabinete.test']);

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $this->payload())
            ->assertSessionHasErrors('responsavel_email');

        $this->assertDatabaseMissing('gabinetes', ['nome' => 'Gabinete Cidadão']);
    }

    public function test_platform_admin_updates_office_and_responsible(): void
    {
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $responsible = User::factory()->forGabinete($office)->councilor()->create();
        $payload = $this->payload([
            'nome' => 'Gabinete Renovado',
            'responsavel_email' => 'novo@gabinete.test',
            'responsavel_password' => '',
            'responsavel_password_confirmation' => '',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.offices.update', $office), $payload)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('gabinetes', ['id' => $office->id, 'nome' => 'Gabinete Renovado']);
        $this->assertDatabaseHas('users', [
            'id' => $responsible->id,
            'email' => 'novo@gabinete.test',
            'role' => UserRole::Councilor->value,
        ]);
    }

    public function test_changing_the_office_municipality_relinks_the_electoral_municipality(): void
    {
        // O fake global de IBGE (setUp) só reconhece "Fortaleza" pra
        // qualquer UF consultada — por isso os dois municípios eleitorais
        // abaixo usam o mesmo nome em UFs diferentes, só pra exercitar o
        // relink sem depender de um nome que a validação rejeitaria.
        Queue::fake();
        $admin = User::factory()->root()->create();
        $fortalezaCe = MunicipioEleitoral::query()->create([
            'codigo_tse' => '10000', 'codigo_ibge' => '2304400', 'nome' => 'Fortaleza', 'uf' => 'CE',
        ]);
        $fortalezaSp = MunicipioEleitoral::query()->create([
            'codigo_tse' => '20000', 'codigo_ibge' => '3548500', 'nome' => 'Fortaleza', 'uf' => 'SP',
        ]);
        $office = Gabinete::factory()->create([
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $fortalezaCe->id,
        ]);
        User::factory()->forGabinete($office)->councilor()->create();

        $this->actingAs($admin)
            ->put(route('admin.offices.update', $office), $this->payload([
                'municipio' => 'Fortaleza',
                'estado' => 'SP',
                'responsavel_password' => '',
                'responsavel_password_confirmation' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($fortalezaSp->id, $office->fresh()->municipio_eleitoral_id);
        Queue::assertPushed(
            SyncOfficeSectionVotesFromGovnexApi::class,
            fn (SyncOfficeSectionVotesFromGovnexApi $job): bool => $job->officeId === $office->id,
        );
    }

    public function test_changing_to_a_municipality_without_electoral_data_unlinks_the_stale_one(): void
    {
        $admin = User::factory()->root()->create();
        $fortalezaCe = MunicipioEleitoral::query()->create([
            'codigo_tse' => '10000', 'codigo_ibge' => '2304400', 'nome' => 'Fortaleza', 'uf' => 'CE',
        ]);
        $office = Gabinete::factory()->create([
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $fortalezaCe->id,
        ]);
        User::factory()->forGabinete($office)->councilor()->create();

        // Não existe "Fortaleza/SP" na base TSE/IBGE do app (só a de CE
        // acima) — simula o gabinete apontando pra um município ainda não
        // importado depois da troca de estado.
        $this->actingAs($admin)
            ->put(route('admin.offices.update', $office), $this->payload([
                'municipio' => 'Fortaleza',
                'estado' => 'SP',
                'responsavel_password' => '',
                'responsavel_password_confirmation' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull($office->fresh()->municipio_eleitoral_id);
    }

    public function test_suspension_blocks_tenant_access_and_reactivation_restores_it(): void
    {
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $tenantUser = User::factory()->forGabinete($office)->create();

        $this->actingAs($admin)
            ->patch(route('admin.offices.status', $office), ['status' => GabineteStatus::Suspended->value])
            ->assertSessionHasNoErrors();

        $this->assertSame(GabineteStatus::Suspended, $office->fresh()->status);
        $this->assertNotNull($office->fresh()->suspended_at);

        $this->actingAs($tenantUser)
            ->get(route('dashboard'))
            ->assertRedirect(route('entidades.index'));
        $this->assertAuthenticatedAs($tenantUser);

        $this->actingAs($admin)
            ->patch(route('admin.offices.status', $office), ['status' => GabineteStatus::Active->value]);

        $this->assertSame(GabineteStatus::Active, $office->fresh()->status);
        $this->assertNull($office->fresh()->suspended_at);
        $this->actingAs($tenantUser->refresh())->get(route('dashboard'))->assertOk();
    }

    public function test_platform_admin_cannot_enter_tenant_operations(): void
    {
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->get(route('demands.index'))
            ->assertForbidden();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        $entidade = Entidade::factory()->create();

        return [
            'entidade_id' => $entidade->id,
            'tipo_gabinete' => GabineteType::IndependentOffice->value,
            'nome' => 'Gabinete Cidadão',
            'vereador_nome' => 'Maria da Silva',
            'numero_eleitoral' => '12345',
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
            'timezone' => 'America/Sao_Paulo',
            'telefone' => '(85) 99999-0000',
            'email' => 'contato@gabinete.test',
            'endereco' => 'Rua da Cidadania, 100',
            'responsavel_nome' => 'Responsável Inicial',
            'responsavel_email' => 'responsavel@gabinete.test',
            'responsavel_password' => 'Senha!Segura2026',
            'responsavel_password_confirmation' => 'Senha!Segura2026',
            ...$overrides,
        ];
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Enums\ElectionType;
use App\Enums\OfficeModule;
use App\Enums\OfficeStatus;
use App\Enums\OfficeType;
use App\Enums\UserRole;
use App\Jobs\PrepareOfficePoliticalData;
use App\Jobs\SyncOfficeSectionVotesFromGovnexApi;
use App\Models\Candidate;
use App\Models\Demand;
use App\Models\Election;
use App\Models\ElectoralMunicipality;
use App\Models\ElectorateSnapshot;
use App\Models\Entity;
use App\Models\Office;
use App\Models\TseSync;
use App\Models\User;
use App\Services\Modules\OfficeModuleManager;
use App\Services\Politics\Polls\PollingDataService;
use App\Services\Politics\Polls\ResultResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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
        $active = Office::factory()->create(['nome' => 'Gabinete Ativo']);
        Office::factory()->suspended()->create();
        $tenantUser = User::factory()->forGabinete($active)->create();
        Demand::factory()->forGabinete($active, creator: $tenantUser)->count(2)->create();

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
        $office = Office::factory()->create();
        $tenantUser = User::factory()->forGabinete($office)->administrator()->create();

        $this->actingAs($tenantUser)
            ->get(route('admin.offices.index'))
            ->assertForbidden();
    }

    public function test_platform_admin_can_list_and_filter_offices(): void
    {
        $admin = User::factory()->root()->create();
        Office::factory()->create(['nome' => 'Gabinete Aurora', 'estado' => 'CE']);
        Office::factory()->suspended()->create(['nome' => 'Gabinete Horizonte', 'estado' => 'SP']);

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
        $municipality = ElectoralMunicipality::query()->create([
            'codigo_tse' => '15890', 'codigo_ibge' => '2304251', 'nome' => 'Cruz', 'uf' => 'CE',
        ]);
        $election = Election::query()->where('ano', 2024)->where('tipo', 'municipal')->firstOrFail();
        $titular = Candidate::query()->create([
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
        ElectorateSnapshot::query()->create([
            'municipio_eleitoral_id' => $municipality->id,
            'ano_referencia' => 2026,
            'data_referencia' => now()->toDateString(),
            'eleitores_aptos' => 12345,
            'fonte_url' => 'https://cdn.tse.jus.br/perfil_eleitorado_2026.zip',
            'fonte_gerada_em' => now(),
        ]);
        Office::factory()->create([
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

    public function test_platform_admin_can_request_pollingdata_sync(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.offices.political-sync.store', $office), [
                'tasks' => ['pollingdata_polls'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, TseSync::query()->count());
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
        $office = Office::factory()->create();
        TseSync::query()->create([
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
        $office = Office::factory()->create();
        app(OfficeModuleManager::class)->sync(
            $office,
            array_values(array_diff(
                app(OfficeModuleManager::class)->allEnabled(),
                [OfficeModule::Politics->value],
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
        $office = Office::factory()->create();
        $tenantUser = User::factory()->forGabinete($office)->administrator()->create();

        $this->actingAs($tenantUser)
            ->get(route('admin.political-sync.index'))
            ->assertForbidden();
    }

    public function test_requeues_an_active_sync_without_creating_a_duplicate_run(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create();
        TseSync::query()->create([
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

        $this->assertSame(1, TseSync::query()->count());
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
        $office = Office::factory()->create();
        $staleRun = TseSync::query()->create([
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
        $this->assertSame(2, TseSync::query()->count());
        Queue::assertPushed(PrepareOfficePoliticalData::class, 1);
    }

    public function test_platform_admin_can_manually_restart_a_stuck_sync(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create();
        $stuckRun = TseSync::query()->create([
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
        $this->assertSame(2, TseSync::query()->count());
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
        $office = Office::factory()->create();
        $completedRun = TseSync::query()->create([
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
        $this->assertSame(1, TseSync::query()->count());
        Queue::assertNotPushed(PrepareOfficePoliticalData::class);
    }

    public function test_cannot_restart_a_sync_that_is_already_processing(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create();
        $processingRun = TseSync::query()->create([
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
        $this->assertSame(1, TseSync::query()->count());
        Queue::assertNotPushed(PrepareOfficePoliticalData::class);
    }

    public function test_cannot_restart_a_sync_belonging_to_another_office(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create();
        $otherOffice = Office::factory()->create();
        $run = TseSync::query()->create([
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
        $office = Office::factory()->create();
        $tenantUser = User::factory()->forGabinete($office)->administrator()->create();
        $run = TseSync::query()->create([
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
        $office = Office::factory()->create();
        $run = TseSync::query()->create([
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
        $this->assertSame(1, TseSync::query()->count());
        Queue::assertNotPushed(PrepareOfficePoliticalData::class);
    }

    public function test_cannot_cancel_a_completed_sync(): void
    {
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create();
        $completedRun = TseSync::query()->create([
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
        $office = Office::factory()->create();
        $processingRun = TseSync::query()->create([
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
        $office = Office::factory()->create();
        $otherOffice = Office::factory()->create();
        $run = TseSync::query()->create([
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
        $office = Office::factory()->create();
        $tenantUser = User::factory()->forGabinete($office)->administrator()->create();
        $run = TseSync::query()->create([
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
        $office = Office::factory()->create();
        $run = TseSync::query()->create([
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
            app(OfficeModuleManager::class),
        );

        $this->assertSame('cancelada', $run->fresh()->situacao);
    }

    public function test_tasks_without_a_past_election_are_skipped_and_reported(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create();
        Election::query()->where('tipo', ElectionType::General)->delete();

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

    /**
     * Os datasets do TSE saíram desta tela: são parte do ciclo de vida de
     * cada eleição (ver ElectionManagementTest). Aqui fica só o PollingData.
     */
    public function test_political_sync_page_only_covers_polling_data(): void
    {
        $admin = User::factory()->root()->create();
        Office::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.political-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/political-sync/index')
                ->where('politicsAvailable', true)
                ->has('pollingData.offices', 1)
                ->missing('globalSyncs')
                ->missing('datasetSlugPatterns')
                ->missing('elections'));
    }

    public function test_platform_admin_updates_office_without_touching_accounts(): void
    {
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create();
        $responsible = User::factory()->forGabinete($office)->administrator()->create([
            'email' => 'responsavel@gabinete.test',
        ]);
        $usersBefore = User::query()->count();
        $payload = $this->payload([
            'nome' => 'Gabinete Renovado',
            'responsavel_email' => 'novo@gabinete.test',
            'responsavel_password' => 'Senha!Segura2026',
            'responsavel_password_confirmation' => 'Senha!Segura2026',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.offices.update', $office), $payload)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('gabinetes', ['id' => $office->id, 'nome' => 'Gabinete Renovado']);
        // Conta do responsável vem do Govnex Hub: a edição do gabinete não a toca.
        $this->assertDatabaseHas('users', [
            'id' => $responsible->id,
            'email' => 'responsavel@gabinete.test',
            'role' => UserRole::Administrator->value,
        ]);
        $this->assertSame($usersBefore, User::query()->count());
    }

    public function test_office_without_administrator_is_updated_without_creating_an_account(): void
    {
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create();
        $usersBefore = User::query()->count();

        $this->actingAs($admin)
            ->put(route('admin.offices.update', $office), $this->payload(['nome' => 'Gabinete Sem Conta']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('gabinetes', ['id' => $office->id, 'nome' => 'Gabinete Sem Conta']);
        $this->assertSame($usersBefore, User::query()->count());
    }

    public function test_changing_the_office_municipality_relinks_the_electoral_municipality(): void
    {
        // O fake global de IBGE (setUp) só reconhece "Fortaleza" pra
        // qualquer UF consultada — por isso os dois municípios eleitorais
        // abaixo usam o mesmo nome em UFs diferentes, só pra exercitar o
        // relink sem depender de um nome que a validação rejeitaria.
        Queue::fake();
        $admin = User::factory()->root()->create();
        $fortalezaCe = ElectoralMunicipality::query()->create([
            'codigo_tse' => '10000', 'codigo_ibge' => '2304400', 'nome' => 'Fortaleza', 'uf' => 'CE',
        ]);
        $fortalezaSp = ElectoralMunicipality::query()->create([
            'codigo_tse' => '20000', 'codigo_ibge' => '3548500', 'nome' => 'Fortaleza', 'uf' => 'SP',
        ]);
        $office = Office::factory()->create([
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $fortalezaCe->id,
        ]);
        User::factory()->forGabinete($office)->administrator()->create();

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
        $fortalezaCe = ElectoralMunicipality::query()->create([
            'codigo_tse' => '10000', 'codigo_ibge' => '2304400', 'nome' => 'Fortaleza', 'uf' => 'CE',
        ]);
        $office = Office::factory()->create([
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
            'municipio_eleitoral_id' => $fortalezaCe->id,
        ]);
        User::factory()->forGabinete($office)->administrator()->create();

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
        $office = Office::factory()->create();
        $tenantUser = User::factory()->forGabinete($office)->create();

        $this->actingAs($admin)
            ->patch(route('admin.offices.status', $office), ['status' => OfficeStatus::Suspended->value])
            ->assertSessionHasNoErrors();

        $this->assertSame(OfficeStatus::Suspended, $office->fresh()->status);
        $this->assertNotNull($office->fresh()->suspended_at);

        $this->actingAs($tenantUser)
            ->get(route('dashboard'))
            ->assertRedirect(route('entidades.index'));
        $this->assertAuthenticatedAs($tenantUser);

        $this->actingAs($admin)
            ->patch(route('admin.offices.status', $office), ['status' => OfficeStatus::Active->value]);

        $this->assertSame(OfficeStatus::Active, $office->fresh()->status);
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
        $entidade = Entity::factory()->create();

        return [
            'entidade_id' => $entidade->id,
            'tipo_gabinete' => OfficeType::IndependentOffice->value,
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

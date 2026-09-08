<?php

namespace Tests\Feature\Admin;

use App\Jobs\SyncElectorateFromGovnexApi;
use App\Models\Gabinete;
use App\Models\SincronizacaoTse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncElectorateFromGovnexApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // authorize() em SyncElectorateFromGovnexApiRequest exige um
        // gabinete ativo com o módulo Política — Gabinete::factory() já
        // vem com todos os módulos ativos por padrão (ver
        // PlatformAdministrationTest::test_platform_admin_can_request_pollingdata_sync).
        Gabinete::factory()->create();
    }

    public function test_platform_admin_can_queue_the_electorate_sync(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/eleitorado/govnex-api', ['ano' => 2026])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $run = SincronizacaoTse::query()->firstOrFail();
        $this->assertNull($run->gabinete_id);
        $this->assertSame($admin->id, $run->solicitado_por_id);
        $this->assertSame('electorate', $run->dataset);
        $this->assertSame(2026, $run->ano);
        $this->assertSame('pendente', $run->situacao);
        Queue::assertPushed(
            SyncElectorateFromGovnexApi::class,
            fn (SyncElectorateFromGovnexApi $job): bool => $job->runId === $run->id,
        );
    }

    public function test_does_not_queue_a_duplicate_active_run(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'electorate',
            'ano' => 2026,
            'fonte_url' => 'https://example.test/govnex-api',
            'situacao' => 'processando',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/eleitorado/govnex-api', ['ano' => 2026])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SincronizacaoTse::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_non_admin_user_is_forbidden(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/admin/sincronizacoes-tse-globais/eleitorado/govnex-api', ['ano' => 2026])
            ->assertForbidden();

        $this->assertSame(0, SincronizacaoTse::query()->count());
    }

    /**
     * Regressão: electorate saiu de UPLOADABLE_DATASETS -> fileBasedDatasets()
     * (ver TsePoliticalDataSyncService::GOVNEX_API_DATASETS) — não deve mais
     * ser aceito pelos fluxos de arquivo, só pelo endpoint da GOVNEX API.
     */
    public function test_electorate_is_rejected_by_the_file_based_upload_and_fallback_endpoints(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/fallback-automatico', [
                'dataset' => 'electorate',
                'ano' => 2026,
            ])
            ->assertSessionHasErrors('dataset');

        $this->assertSame(0, SincronizacaoTse::query()->count());
        Queue::assertNothingPushed();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Jobs\SyncDatasetFromGovnexApi;
use App\Models\Gabinete;
use App\Models\SincronizacaoTse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncFromGovnexApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // authorize() em SyncFromGovnexApiRequest exige um gabinete ativo com
        // o módulo Política — Gabinete::factory() já vem com todos os módulos
        // ativos por padrão, e sem titular resolvido.
        Gabinete::factory()->create();
    }

    public function test_platform_admin_can_queue_the_electorate_sync(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/govnex-api', ['dataset' => 'electorate', 'ano' => 2026])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $run = SincronizacaoTse::query()->firstOrFail();
        $this->assertNull($run->gabinete_id);
        $this->assertSame($admin->id, $run->solicitado_por_id);
        $this->assertSame('electorate', $run->dataset);
        $this->assertSame(2026, $run->ano);
        $this->assertSame('pendente', $run->situacao);
        Queue::assertPushed(
            SyncDatasetFromGovnexApi::class,
            fn (SyncDatasetFromGovnexApi $job): bool => $job->runId === $run->id,
        );
    }

    /** Todos os datasets do TSE passam pelo mesmo endpoint. */
    public function test_every_tse_dataset_is_queued_through_the_same_endpoint(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $payloads = [
            ['dataset' => 'municipalities'],
            ['dataset' => 'electorate', 'ano' => 2026],
            ['dataset' => 'candidates', 'ano' => 2024],
            ['dataset' => 'turnout', 'ano' => 2024],
            ['dataset' => 'candidate_votes', 'ano' => 2024],
            ['dataset' => 'polling_locations', 'ano' => 2024],
            ['dataset' => 'poll_registry', 'ano' => 2026],
        ];

        foreach ($payloads as $payload) {
            $this->actingAs($admin)
                ->post('/admin/sincronizacoes-tse-globais/govnex-api', $payload)
                ->assertSessionHasNoErrors()
                ->assertRedirect();
        }

        $this->assertSame(
            array_column($payloads, 'dataset'),
            SincronizacaoTse::query()->orderBy('id')->pluck('dataset')->all(),
        );
        Queue::assertPushed(SyncDatasetFromGovnexApi::class, count($payloads));
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
            ->post('/admin/sincronizacoes-tse-globais/govnex-api', ['dataset' => 'electorate', 'ano' => 2026])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SincronizacaoTse::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_non_admin_user_is_forbidden(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/admin/sincronizacoes-tse-globais/govnex-api', ['dataset' => 'electorate', 'ano' => 2026])
            ->assertForbidden();

        $this->assertSame(0, SincronizacaoTse::query()->count());
    }

    /**
     * A base TSE/IBGE não é publicada por ano (YEARLESS_DATASETS), então o
     * endpoint aceita a solicitação sem `ano`.
     */
    public function test_platform_admin_can_queue_the_municipalities_sync_without_a_year(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/govnex-api', ['dataset' => 'municipalities'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $run = SincronizacaoTse::query()->firstOrFail();
        $this->assertSame('municipalities', $run->dataset);
        $this->assertSame('pendente', $run->situacao);
        Queue::assertPushed(SyncDatasetFromGovnexApi::class);
    }

    public function test_datasets_published_by_year_require_a_year(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/govnex-api', ['dataset' => 'candidates'])
            ->assertSessionHasErrors('ano');

        Queue::assertNothingPushed();
    }

    public function test_an_unknown_dataset_is_rejected(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/govnex-api', ['dataset' => 'arquivo_zip', 'ano' => 2026])
            ->assertSessionHasErrors('dataset');

        $this->assertSame(0, SincronizacaoTse::query()->count());
        Queue::assertNothingPushed();
    }

    /** Votação nominal só existe em eleição municipal; 2026 é eleição geral. */
    public function test_a_dataset_outside_its_election_type_is_rejected(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/govnex-api', ['dataset' => 'candidate_votes', 'ano' => 2026])
            ->assertSessionHasErrors('dataset');

        $this->assertSame(0, SincronizacaoTse::query()->count());
        Queue::assertNothingPushed();
    }

    /** Sem titular resolvido em nenhum gabinete, não há de quem gravar votos. */
    public function test_section_votes_require_an_office_with_a_resolved_titular(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/govnex-api', ['dataset' => 'section_votes', 'ano' => 2024])
            ->assertSessionHasErrors('dataset');

        $this->assertSame(0, SincronizacaoTse::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_platform_admin_can_cancel_a_stuck_sync_and_queue_it_again(): void
    {
        $admin = User::factory()->root()->create();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'municipalities',
            'ano' => now()->year,
            'fonte_url' => 'https://example.test/govnex-api',
            'situacao' => 'processando',
            'progresso_etapa' => 'lendo_govnex_api',
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

        // Cancelado, dá pra sincronizar de novo sem cair no bloqueio de duplicata.
        Queue::fake();
        $this->actingAs($admin)
            ->post('/admin/sincronizacoes-tse-globais/govnex-api', ['dataset' => 'municipalities'])
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
            'fonte_url' => 'https://example.test/govnex-api',
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
}

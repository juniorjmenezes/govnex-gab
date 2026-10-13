<?php

namespace Tests\Feature\Admin;

use App\Jobs\SyncDatasetFromGovnexApi;
use App\Models\Eleicao;
use App\Models\Gabinete;
use App\Models\SincronizacaoTse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sincronização dos datasets do TSE dentro do ciclo de vida da eleição
 * (`admin/eleicoes/{eleicao}/sincronizacoes`): o ano vem da eleição e só
 * valem os datasets do tipo dela.
 */
class ElectionDatasetSyncTest extends TestCase
{
    use RefreshDatabase;

    private Eleicao $general;

    private Eleicao $municipal;

    protected function setUp(): void
    {
        parent::setUp();

        // authorize() em SyncElectionDatasetRequest exige um gabinete ativo
        // com o módulo Política — Gabinete::factory() já vem com todos os
        // módulos ativos por padrão, e sem titular resolvido.
        Gabinete::factory()->create();
        // Eleições-semente da migration do painel político.
        $this->general = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $this->municipal = Eleicao::query()->where('ano', 2024)->firstOrFail();
    }

    public function test_platform_admin_can_queue_the_electorate_sync_for_the_election_year(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post(route('admin.elections.datasets.sync', $this->general), ['dataset' => 'electorate'])
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

    /** Cada dataset vale na eleição do seu tipo, pelo mesmo endpoint. */
    public function test_every_tse_dataset_is_queued_through_its_election(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();
        $requests = [
            [$this->general, 'municipalities'],
            [$this->general, 'electorate'],
            [$this->municipal, 'candidates'],
            [$this->municipal, 'turnout'],
            [$this->municipal, 'candidate_votes'],
            [$this->municipal, 'polling_locations'],
            [$this->general, 'poll_registry'],
        ];

        foreach ($requests as [$election, $dataset]) {
            $this->actingAs($admin)
                ->post(route('admin.elections.datasets.sync', $election), ['dataset' => $dataset])
                ->assertSessionHasNoErrors()
                ->assertRedirect();
        }

        $this->assertSame(
            array_column($requests, 1),
            SincronizacaoTse::query()->orderBy('id')->pluck('dataset')->all(),
        );
        $this->assertSame(
            [now()->year, 2026, 2024, 2024, 2024, 2024, 2026],
            SincronizacaoTse::query()->orderBy('id')->pluck('ano')->all(),
        );
        Queue::assertPushed(SyncDatasetFromGovnexApi::class, count($requests));
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
            ->post(route('admin.elections.datasets.sync', $this->general), ['dataset' => 'electorate'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SincronizacaoTse::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_non_admin_user_is_forbidden(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.elections.datasets.sync', $this->general), ['dataset' => 'electorate'])
            ->assertForbidden();

        $this->assertSame(0, SincronizacaoTse::query()->count());
    }

    public function test_an_unknown_dataset_is_rejected(): void
    {
        Queue::fake();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post(route('admin.elections.datasets.sync', $this->general), ['dataset' => 'arquivo_zip'])
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
            ->post(route('admin.elections.datasets.sync', $this->general), ['dataset' => 'candidate_votes'])
            ->assertSessionHasErrors('dataset');
        $this->actingAs($admin)
            ->post(route('admin.elections.datasets.sync', $this->municipal), ['dataset' => 'poll_registry'])
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
            ->post(route('admin.elections.datasets.sync', $this->municipal), ['dataset' => 'section_votes'])
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
            ->post(route('admin.elections.datasets.cancel', [$this->general, $run]))
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
            ->post(route('admin.elections.datasets.sync', $this->general), ['dataset' => 'municipalities'])
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
            ->post(route('admin.elections.datasets.cancel', [$this->general, $run]))
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
            ->post(route('admin.elections.datasets.cancel', [$this->general, $run]))
            ->assertNotFound();

        $this->assertSame('processando', $run->fresh()->situacao);
    }

    /** A execução de outra eleição (outro ano) não é cancelada por esta. */
    public function test_cancel_does_not_reach_a_run_of_another_election(): void
    {
        $admin = User::factory()->root()->create();
        $run = SincronizacaoTse::query()->create([
            'gabinete_id' => null,
            'dataset' => 'candidates',
            'ano' => 2024,
            'fonte_url' => 'https://example.test/govnex-api',
            'situacao' => 'processando',
            'iniciada_em' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.elections.datasets.cancel', [$this->general, $run]))
            ->assertNotFound();

        $this->assertSame('processando', $run->fresh()->situacao);
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Enums\AppointmentRecurrence;
use App\Enums\AppointmentStatus;
use App\Enums\EntityModule;
use App\Enums\OfficeModule;
use App\Models\Citizen;
use App\Models\Demand;
use App\Models\Entity;
use App\Models\Event;
use App\Models\Office;
use App\Models\OfficeModuleEvent;
use App\Models\User;
use App\Services\Modules\OfficeModuleManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class OfficeModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([
                ['id' => 2304400, 'nome' => 'Fortaleza'],
            ]),
        ]);
    }

    public function test_existing_behavior_is_preserved_with_every_module_enabled(): void
    {
        $office = Office::factory()->create();

        $this->assertSame(
            app(OfficeModuleManager::class)->allEnabled(),
            app(OfficeModuleManager::class)->activeFor($office),
        );
        $this->assertDatabaseCount('gabinete_modulos', count(app(OfficeModuleManager::class)->allEnabled()));
    }

    public function test_demo_seeder_enables_every_module_for_each_seeded_office(): void
    {
        $this->seed(DatabaseSeeder::class);

        $offices = Office::withoutGlobalScopes()->get();
        $entidades = Entity::withoutGlobalScopes()->get();
        $this->assertCount(7, $offices);
        $this->assertCount(2, $entidades);

        // Toda unidade recebe o conjunto completo de módulos, exceto a
        // Secretaria legislativa: ela só tem o catálogo restrito aos
        // módulos que lhe fazem sentido (sem Demandas/Relacionamento — ver
        // AGENTS.md).
        foreach ($offices as $office) {
            $this->assertNotNull($office->entidade_id);
            $expected = app(OfficeModuleManager::class)->allEnabled($office->tipo_gabinete);
            $this->assertSame($expected, app(OfficeModuleManager::class)->activeFor($office));
        }

        $this->assertDatabaseCount(
            'gabinete_modulos',
            $offices->sum(fn (Office $office): int => count(app(OfficeModuleManager::class)->allEnabled($office->tipo_gabinete))),
        );
        $this->assertDatabaseCount(
            'entidade_modulos',
            $entidades->sum(fn (Entity $entidade): int => count(EntityModule::availableCases($entidade->tipo))),
        );
        $this->assertDatabaseCount('entidade_membros', 9);
        $this->assertDatabaseCount('gabinete_membros', 9);
        $this->assertDatabaseCount('entidade_bairros', 18);
        $this->assertSame(0, DB::table('bairros')->whereNull('entidade_bairro_id')->count());
    }

    public function test_active_modules_are_cached_only_for_the_current_manager_scope(): void
    {
        $office = Office::factory()->create();
        $manager = app(OfficeModuleManager::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $manager->activeFor($office);
        $queriesAfterFirstRead = count(DB::getQueryLog());
        $manager->activeFor($office);

        $this->assertGreaterThan(0, $queriesAfterFirstRead);
        $this->assertCount($queriesAfterFirstRead, DB::getQueryLog());

        DB::disableQueryLog();
    }

    public function test_platform_admin_can_replace_the_complete_module_selection_and_audit_changes(): void
    {
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create();
        $selection = [
            OfficeModule::Relationship->value,
            OfficeModule::Schedule->value,
        ];

        $this->actingAs($admin)
            ->patch(route('admin.offices.modules.update', $office), ['modules' => $selection])
            ->assertSessionHasNoErrors();

        $this->assertSame($selection, app(OfficeModuleManager::class)->activeFor($office));
        // Um evento por módulo desativado.
        $disabledCount = count(app(OfficeModuleManager::class)->allEnabled()) - count($selection);
        $this->assertDatabaseCount('gabinete_modulo_eventos', $disabledCount);
        $this->assertDatabaseHas('gabinete_modulo_eventos', [
            'gabinete_id' => $office->id,
            'modulo' => OfficeModule::Demands->value,
            'acao' => 'DESATIVADO',
            'administrador_id' => $admin->id,
        ]);

        $this->patch(route('admin.offices.modules.update', $office), ['modules' => $selection])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('gabinete_modulo_eventos', $disabledCount);

        $this->patch(route('admin.offices.modules.update', $office), ['modules' => []])
            ->assertSessionHasNoErrors();
        $this->assertSame([], app(OfficeModuleManager::class)->activeFor($office));
        $this->assertDatabaseCount('gabinete_modulo_eventos', count(app(OfficeModuleManager::class)->allEnabled()));
    }

    public function test_invalid_dependencies_are_rejected_without_changing_the_office(): void
    {
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create();

        $this->actingAs($admin)
            ->from(route('admin.offices.index'))
            ->patch(route('admin.offices.modules.update', $office), [
                'modules' => [OfficeModule::Reports->value],
            ])
            ->assertSessionHasErrors('modules');

        $this->assertCount(
            count(app(OfficeModuleManager::class)->allEnabled()),
            app(OfficeModuleManager::class)->activeFor($office),
        );
        $this->assertDatabaseCount('gabinete_modulo_eventos', 0);

        $this->patch(route('admin.offices.modules.update', $office), [
            'modules' => [OfficeModule::WhatsApp->value],
        ])->assertSessionHasErrors('modules');
    }

    public function test_only_platform_admin_can_manage_modules(): void
    {
        $office = Office::factory()->create();
        $tenantUser = User::factory()->administrator()->forGabinete($office)->create();

        $this->actingAs($tenantUser)
            ->patch(route('admin.offices.modules.update', $office), ['modules' => []])
            ->assertForbidden();

        $this->assertCount(
            count(app(OfficeModuleManager::class)->allEnabled()),
            app(OfficeModuleManager::class)->activeFor($office),
        );
    }

    public function test_disabled_module_returns_a_specific_403_without_affecting_other_offices(): void
    {
        $admin = User::factory()->root()->create();
        $restrictedOffice = Office::factory()->create();
        $activeOffice = Office::factory()->create();
        $restrictedUser = User::factory()->operator()->forGabinete($restrictedOffice)->create();
        $activeUser = User::factory()->operator()->forGabinete($activeOffice)->create();
        $selection = array_values(array_filter(
            app(OfficeModuleManager::class)->allEnabled(),
            fn (string $module): bool => ! in_array($module, [
                OfficeModule::Demands->value,
                OfficeModule::Reports->value,
            ], true),
        ));

        app(OfficeModuleManager::class)->sync($restrictedOffice, $selection, $admin);

        $this->actingAs($restrictedUser)
            ->get(route('demands.index'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('errors/module-disabled')
                ->where('module.code', OfficeModule::Demands->value));

        $this->getJson(route('demands.index'))
            ->assertForbidden()
            ->assertJsonPath('module', OfficeModule::Demands->value);

        $this->actingAs($activeUser)
            ->get(route('demands.index'))
            ->assertOk();
    }

    public function test_dashboard_remains_useful_when_demands_are_disabled(): void
    {
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $citizen = Citizen::factory()->forGabinete($office)->create();
        Demand::factory()->forGabinete($office, $citizen, creator: $user)->create();

        app(OfficeModuleManager::class)->sync(
            $office,
            [OfficeModule::Relationship->value],
            $admin,
        );

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('capabilities.relationship', true)
                ->where('capabilities.demands', false)
                ->where('metrics.citizens', 1)
                ->where('metrics.open_total', 0)
                ->has('recentDemands', 0));
    }

    public function test_cross_module_fields_are_rejected_and_events_are_hidden_from_agenda(): void
    {
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->administrator()->forGabinete($office)->create();
        $citizen = Citizen::factory()->forGabinete($office)->create();
        $demand = Demand::factory()->forGabinete($office, $citizen, creator: $user)->create();
        Event::factory()->forGabinete($office, $user, $user)->create([
            'inicio_em' => now()->addDay(),
            'fim_em' => now()->addDay()->addHour(),
        ]);

        app(OfficeModuleManager::class)->sync($office, [
            OfficeModule::Relationship->value,
            OfficeModule::Attendances->value,
            OfficeModule::Schedule->value,
        ], $admin);

        $this->actingAs($user)
            ->get(route('appointments.index', [
                'view' => 'dia',
                'date' => now()->addDay()->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('capabilities.events', false)
                ->where('capabilities.demands', false)
                ->has('appointments', 0));

        $this->post(route('appointments.store'), $this->appointmentPayload([
            'cidadao_id' => $citizen->id,
            'demanda_id' => $demand->id,
            'responsavel_id' => $user->id,
        ]))->assertSessionHasErrors('demanda_id');

        $this->post(route('attendances.store'), [
            'cidadao_id' => $citizen->id,
            'atendente_id' => $user->id,
            'demanda_id' => $demand->id,
            'assunto' => 'Orientação ao cidadão',
            'relato' => 'Registro detalhado do atendimento realizado no gabinete.',
            'providencias' => null,
            'atendido_em' => now()->subHour()->format('Y-m-d\TH:i'),
            'duracao_minutos' => 30,
            'requer_retorno' => false,
            'retorno_previsto_em' => null,
        ])->assertSessionHasErrors('demanda_id');

        $this->get(route('events.index'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('errors/module-disabled'));
    }

    public function test_module_audit_events_cannot_be_changed_or_deleted_through_the_model(): void
    {
        $admin = User::factory()->root()->create();
        $office = Office::factory()->create();
        app(OfficeModuleManager::class)->sync($office, [], $admin);
        $event = OfficeModuleEvent::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $event->update(['acao' => 'ALTERADO']);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function appointmentPayload(array $overrides = []): array
    {
        return [
            'titulo' => 'Reunião de acompanhamento',
            'descricao' => null,
            'inicio_em' => now()->addDay()->format('Y-m-d\TH:i'),
            'fim_em' => now()->addDay()->addHour()->format('Y-m-d\TH:i'),
            'dia_inteiro' => false,
            'local' => 'Gabinete',
            'responsavel_id' => null,
            'participantes' => [],
            'cidadao_id' => null,
            'demanda_id' => null,
            'tipo' => 'Reunião',
            'status' => AppointmentStatus::Scheduled->value,
            'observacoes' => null,
            'recorrencia' => AppointmentRecurrence::None->value,
            'recorrencia_ate' => null,
            'lembretes' => [],
            ...$overrides,
        ];
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Enums\AppointmentRecurrence;
use App\Enums\AppointmentStatus;
use App\Enums\EntidadeModule;
use App\Enums\GabineteModule;
use App\Enums\GabineteType;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\Entidade;
use App\Models\Evento;
use App\Models\Gabinete;
use App\Models\GabineteModuloEvento;
use App\Models\User;
use App\Services\Modules\GabineteModuleManager;
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
        $office = Gabinete::factory()->create();

        $this->assertSame(
            array_column(GabineteModule::cases(), 'value'),
            app(GabineteModuleManager::class)->activeFor($office),
        );
        $this->assertDatabaseCount('gabinete_modulos', count(GabineteModule::cases()));
    }

    public function test_demo_seeder_enables_every_module_for_each_seeded_office(): void
    {
        $this->seed(DatabaseSeeder::class);

        $offices = Gabinete::withoutGlobalScopes()->get();
        $entidades = Entidade::withoutGlobalScopes()->get();
        $this->assertCount(7, $offices);
        $this->assertCount(4, $entidades);

        foreach ($offices as $office) {
            $this->assertNotNull($office->entidade_id);
            $this->assertSame(
                array_column(GabineteModule::cases(), 'value'),
                app(GabineteModuleManager::class)->activeFor($office),
            );
        }

        $this->assertDatabaseCount(
            'gabinete_modulos',
            count(GabineteModule::cases()) * $offices->count(),
        );
        $this->assertDatabaseCount(
            'entidade_modulos',
            count(EntidadeModule::cases()) * $entidades->count(),
        );
        $this->assertDatabaseCount('entidade_membros', 9);
        $this->assertDatabaseCount('gabinete_membros', 9);
        $this->assertDatabaseCount('entidade_bairros', 14);
        $this->assertSame(0, DB::table('bairros')->whereNull('entidade_bairro_id')->count());
    }

    public function test_active_modules_are_cached_only_for_the_current_manager_scope(): void
    {
        $office = Gabinete::factory()->create();
        $manager = app(GabineteModuleManager::class);

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
        $office = Gabinete::factory()->create();
        $selection = [
            GabineteModule::Relationship->value,
            GabineteModule::Schedule->value,
        ];

        $this->actingAs($admin)
            ->patch(route('admin.offices.modules.update', $office), ['modules' => $selection])
            ->assertSessionHasNoErrors();

        $this->assertSame($selection, app(GabineteModuleManager::class)->activeFor($office));
        $this->assertDatabaseCount('gabinete_modulo_eventos', 6);
        $this->assertDatabaseHas('gabinete_modulo_eventos', [
            'gabinete_id' => $office->id,
            'modulo' => GabineteModule::Demands->value,
            'acao' => 'DESATIVADO',
            'administrador_id' => $admin->id,
        ]);

        $this->patch(route('admin.offices.modules.update', $office), ['modules' => $selection])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('gabinete_modulo_eventos', 6);

        $this->patch(route('admin.offices.modules.update', $office), ['modules' => []])
            ->assertSessionHasNoErrors();
        $this->assertSame([], app(GabineteModuleManager::class)->activeFor($office));
        $this->assertDatabaseCount('gabinete_modulo_eventos', 8);
    }

    public function test_new_office_uses_the_selected_modules_without_starting_tse_sync(): void
    {
        $admin = User::factory()->root()->create();
        $entidade = Entidade::factory()->create();
        $payload = $this->officePayload([
            'entidade_id' => $entidade->id,
            'tipo_gabinete' => GabineteType::IndependentOffice->value,
            'modules' => [GabineteModule::Relationship->value],
            'sincronizar_tse' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $payload)
            ->assertSessionHasNoErrors();

        $office = Gabinete::withoutGlobalScopes()->where('nome', 'Gabinete Modular')->firstOrFail();
        $this->assertSame(
            [GabineteModule::Relationship->value],
            app(GabineteModuleManager::class)->activeFor($office),
        );
        $this->assertDatabaseCount('gabinete_modulos', count(GabineteModule::cases()));
        $this->assertDatabaseCount('gabinete_modulo_eventos', count(GabineteModule::cases()));
        $this->assertDatabaseCount('sincronizacoes_tse', 0);
    }

    public function test_invalid_dependencies_are_rejected_without_changing_the_office(): void
    {
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();

        $this->actingAs($admin)
            ->from(route('admin.offices.index'))
            ->patch(route('admin.offices.modules.update', $office), [
                'modules' => [GabineteModule::Reports->value],
            ])
            ->assertSessionHasErrors('modules');

        $this->assertCount(
            count(GabineteModule::cases()),
            app(GabineteModuleManager::class)->activeFor($office),
        );
        $this->assertDatabaseCount('gabinete_modulo_eventos', 0);

        $this->patch(route('admin.offices.modules.update', $office), [
            'modules' => [GabineteModule::WhatsApp->value],
        ])->assertSessionHasErrors('modules');
    }

    public function test_only_platform_admin_can_manage_modules(): void
    {
        $office = Gabinete::factory()->create();
        $tenantUser = User::factory()->chiefOfStaff()->forGabinete($office)->create();

        $this->actingAs($tenantUser)
            ->patch(route('admin.offices.modules.update', $office), ['modules' => []])
            ->assertForbidden();

        $this->assertCount(
            count(GabineteModule::cases()),
            app(GabineteModuleManager::class)->activeFor($office),
        );
    }

    public function test_disabled_module_returns_a_specific_403_without_affecting_other_offices(): void
    {
        $admin = User::factory()->root()->create();
        $restrictedOffice = Gabinete::factory()->create();
        $activeOffice = Gabinete::factory()->create();
        $restrictedUser = User::factory()->advisor()->forGabinete($restrictedOffice)->create();
        $activeUser = User::factory()->advisor()->forGabinete($activeOffice)->create();
        $selection = array_values(array_filter(
            array_column(GabineteModule::cases(), 'value'),
            fn (string $module): bool => ! in_array($module, [
                GabineteModule::Demands->value,
                GabineteModule::Reports->value,
            ], true),
        ));

        app(GabineteModuleManager::class)->sync($restrictedOffice, $selection, $admin);

        $this->actingAs($restrictedUser)
            ->get(route('demands.index'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('errors/module-disabled')
                ->where('module.code', GabineteModule::Demands->value));

        $this->getJson(route('demands.index'))
            ->assertForbidden()
            ->assertJsonPath('module', GabineteModule::Demands->value);

        $this->actingAs($activeUser)
            ->get(route('demands.index'))
            ->assertOk();
    }

    public function test_dashboard_remains_useful_when_demands_are_disabled(): void
    {
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $user = User::factory()->advisor()->forGabinete($office)->create();
        $citizen = Cidadao::factory()->forGabinete($office)->create();
        Demanda::factory()->forGabinete($office, $citizen, creator: $user)->create();

        app(GabineteModuleManager::class)->sync(
            $office,
            [GabineteModule::Relationship->value],
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
        $office = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $citizen = Cidadao::factory()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office, $citizen, creator: $user)->create();
        Evento::factory()->forGabinete($office, $user, $user)->create([
            'inicio_em' => now()->addDay(),
            'fim_em' => now()->addDay()->addHour(),
        ]);

        app(GabineteModuleManager::class)->sync($office, [
            GabineteModule::Relationship->value,
            GabineteModule::Attendances->value,
            GabineteModule::Schedule->value,
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
        $office = Gabinete::factory()->create();
        app(GabineteModuleManager::class)->sync($office, [], $admin);
        $event = GabineteModuloEvento::query()->firstOrFail();

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

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function officePayload(array $overrides = []): array
    {
        return [
            'nome' => 'Gabinete Modular',
            'vereador_nome' => 'Representante Modular',
            'numero_eleitoral' => '40000',
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
            'timezone' => 'America/Fortaleza',
            'telefone' => '(85) 99999-0000',
            'email' => 'modular@gabinete.test',
            'endereco' => 'Rua dos Módulos, 100',
            'responsavel_nome' => 'Responsável Modular',
            'responsavel_email' => 'responsavel.modular@gabinete.test',
            'responsavel_password' => 'Senha!Segura2026',
            'responsavel_password_confirmation' => 'Senha!Segura2026',
            ...$overrides,
        ];
    }
}

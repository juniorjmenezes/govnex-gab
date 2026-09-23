<?php

namespace Tests\Feature;

use App\Enums\AccessRole;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Papéis simplificados: root · administrador · operador · auditor.
 *
 * O auditor só lê — a recusa acontece num ponto só (`ResolveEntidadeContext`)
 * e as Policies (`canWrite`) são a segunda camada.
 */
class AccessRoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_auditor_reads_demands_and_agenda(): void
    {
        [$office, $auditor] = $this->officeWithAuditor();
        $demand = Demanda::factory()->forGabinete($office)->create();

        $this->actingAs($auditor);

        $this->get($this->contextRoute($office, 'demands.index'))->assertOk();
        $this->get($this->contextRoute($office, 'demands.show', ['demanda' => $demand->id]))->assertOk();
        $this->get($this->contextRoute($office, 'appointments.index'))->assertOk();
    }

    public function test_auditor_cannot_write_demands(): void
    {
        [$office, $auditor] = $this->officeWithAuditor();
        $demand = Demanda::factory()->forGabinete($office)->create();
        $citizen = Cidadao::factory()->forGabinete($office)->create();

        $this->actingAs($auditor);

        $this->post($this->contextRoute($office, 'demands.store'), [
            'cidadao_id' => $citizen->id,
            'titulo' => 'Tentativa do auditor',
            'descricao' => 'Não deveria gravar.',
            'prioridade' => 'normal',
            'origem' => 'whatsapp',
        ])->assertForbidden()
            ->assertSee('Seu perfil de auditor permite apenas consultar', false);

        $this->put($this->contextRoute($office, 'demands.update', ['demanda' => $demand->id]), [
            'titulo' => 'Alterado pelo auditor',
        ])->assertForbidden();

        $this->delete($this->contextRoute($office, 'demands.destroy', ['demanda' => $demand->id]))
            ->assertForbidden();

        $this->assertSame(1, Demanda::withoutGlobalScopes()->count());
        $this->assertNotSame('Alterado pelo auditor', $demand->fresh()->titulo);
        $this->assertNull($demand->fresh()->deleted_at);
    }

    public function test_auditor_cannot_write_agenda(): void
    {
        [$office, $auditor] = $this->officeWithAuditor();
        $appointment = Appointment::factory()->forGabinete($office)->create();

        $this->actingAs($auditor);

        $this->post($this->contextRoute($office, 'appointments.store'), [
            'titulo' => 'Compromisso do auditor',
            'inicio_em' => '2026-10-10T09:00',
            'fim_em' => '2026-10-10T10:00',
            'status' => AppointmentStatus::Scheduled->value,
        ])->assertForbidden();

        $this->put($this->contextRoute($office, 'appointments.update', ['appointment' => $appointment->id]), [
            'titulo' => 'Alterado pelo auditor',
        ])->assertForbidden();

        $this->delete($this->contextRoute($office, 'appointments.destroy', ['appointment' => $appointment->id]))
            ->assertForbidden();

        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
        $this->assertNotSame('Alterado pelo auditor', $appointment->fresh()->titulo);
    }

    public function test_auditor_keeps_personal_actions_outside_the_office_data(): void
    {
        [$office, $auditor] = $this->officeWithAuditor();

        $this->actingAs($auditor)
            ->patch($this->contextRoute($office, 'notifications.read-all'))
            ->assertRedirect();

        $this->actingAs($auditor)
            ->patch(route('profile.update'), [
                'name' => 'Auditora Renomeada',
                'email' => $auditor->email,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('Auditora Renomeada', $auditor->fresh()->name);

        $this->actingAs($auditor)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_policies_deny_writes_to_the_auditor(): void
    {
        [$office, $auditor] = $this->officeWithAuditor();
        $demand = Demanda::factory()->forGabinete($office)->create();
        $appointment = Appointment::factory()->forGabinete($office)->create();

        $this->assertTrue(Gate::forUser($auditor)->allows('view', $demand));
        $this->assertFalse(Gate::forUser($auditor)->allows('create', Demanda::class));
        $this->assertFalse(Gate::forUser($auditor)->allows('update', $demand));
        $this->assertFalse(Gate::forUser($auditor)->allows('delete', $demand));
        $this->assertFalse(Gate::forUser($auditor)->allows('create', Appointment::class));
        $this->assertFalse(Gate::forUser($auditor)->allows('cancel', $appointment));
    }

    public function test_operator_writes_but_does_not_delete_or_manage_the_team(): void
    {
        $office = Gabinete::factory()->create();
        $operator = User::factory()->operator()->forGabinete($office)->create();
        $teammate = User::factory()->operator()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office)->create();

        $this->assertTrue(Gate::forUser($operator)->allows('create', Demanda::class));
        $this->assertTrue(Gate::forUser($operator)->allows('update', $demand));
        $this->assertFalse(Gate::forUser($operator)->allows('delete', $demand));
        $this->assertFalse(Gate::forUser($operator)->allows('update', $teammate));

        $this->actingAs($operator)
            ->get($this->contextRoute($office, 'team.index'))
            ->assertForbidden();

        $this->actingAs($operator)
            ->post($this->contextRoute($office, 'team.store'), [
                'name' => 'Cadastro indevido',
                'email' => 'indevido@example.test',
                'role' => AccessRole::Operator->value,
                'password' => 'Senha123!Forte',
                'password_confirmation' => 'Senha123!Forte',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'indevido@example.test']);
    }

    public function test_administrator_manages_operators_auditors_and_other_administrators(): void
    {
        $office = Gabinete::factory()->create();
        $administrator = User::factory()->administrator()->forGabinete($office)->create();
        $otherAdministrator = User::factory()->administrator()->forGabinete($office)->create();
        $root = User::factory()->root()->create();

        $this->assertTrue(Gate::forUser($administrator)->allows('update', $otherAdministrator));
        $this->assertFalse(Gate::forUser($administrator)->allows('update', $root));

        $this->actingAs($administrator)
            ->get($this->contextRoute($office, 'team.index'))
            ->assertOk();

        foreach ([
            'auditor@example.test' => AccessRole::Auditor,
            'administrador@example.test' => AccessRole::Administrator,
        ] as $email => $role) {
            $this->actingAs($administrator)
                ->post($this->contextRoute($office, 'team.store'), [
                    'name' => 'Nova pessoa',
                    'email' => $email,
                    'role' => $role->value,
                    'password' => 'Senha123!Forte',
                    'password_confirmation' => 'Senha123!Forte',
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            $this->assertSame(
                $role,
                GabineteMembro::query()
                    ->where('gabinete_id', $office->id)
                    ->where('usuario_id', User::query()->where('email', $email)->value('id'))
                    ->value('papel'),
            );
        }

        $this->actingAs($administrator)
            ->put($this->contextRoute($office, 'team.update', ['usuario' => $otherAdministrator->id]), [
                'role' => AccessRole::Auditor->value,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(AccessRole::Auditor, $otherAdministrator->gabineteRole($office->id));
    }

    /** @return array{Gabinete, User} */
    private function officeWithAuditor(): array
    {
        $office = Gabinete::factory()->create();
        $auditor = User::factory()->auditor()->forGabinete($office)->create();

        return [$office, $auditor];
    }

    /** @param  array<string, mixed>  $parameters */
    private function contextRoute(Gabinete $office, string $name, array $parameters = []): string
    {
        return route('context.'.$name, [
            'entidade' => $office->entidade->slug,
            'gabinete' => $office->slug,
            ...$parameters,
        ]);
    }
}

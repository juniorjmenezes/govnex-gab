<?php

namespace Tests\Feature;

use App\Models\Atendimento;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\Gabinete;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_list_is_strictly_isolated_by_office(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $ownAttendance = Atendimento::factory()->forGabinete($office)->create();
        $foreignAttendance = Atendimento::factory()->forGabinete($otherOffice)->create();

        $this->actingAs($user)
            ->get(route('attendances.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('attendances/index')
                ->has('attendances.data', 1)
                ->where('attendances.data.0.id', $ownAttendance->id));

        $this->get(route('attendances.show', $foreignAttendance))
            ->assertNotFound();
    }

    public function test_user_can_register_an_in_person_attendance(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10 15:00:00', 'UTC'));
        $office = Gabinete::factory()->create([
            'timezone' => 'America/Sao_Paulo',
        ]);
        $user = User::factory()->operator()->forGabinete($office)->create();
        $citizen = Cidadao::factory()->forGabinete($office)->create([
            'eleitor' => true,
        ]);
        $demand = Demanda::factory()
            ->forGabinete($office, $citizen, creator: $user)
            ->create();

        $this->actingAs($user)
            ->post(route('attendances.store'), [
                'cidadao_id' => $citizen->id,
                'atendente_id' => $user->id,
                'demanda_id' => $demand->id,
                'assunto' => 'Regularização de documento',
                'relato' => 'O cidadão compareceu ao gabinete para solicitar orientação.',
                'providencias' => 'Foram entregues as orientações e os contatos necessários.',
                'atendido_em' => '2026-08-10T10:30',
                'duracao_minutos' => 35,
                'requer_retorno' => true,
                'retorno_previsto_em' => '2026-08-17',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $attendance = Atendimento::withoutGlobalScopes()->sole();
        $this->assertSame($office->id, $attendance->gabinete_id);
        $this->assertSame($citizen->id, $attendance->cidadao_id);
        $this->assertSame($user->id, $attendance->atendente_id);
        $this->assertSame($user->id, $attendance->criado_por_id);
        $this->assertSame($demand->id, $attendance->demanda_id);
        $this->assertSame('2026-08-10 13:30:00', $attendance->atendido_em->format('Y-m-d H:i:s'));
        $this->assertTrue($attendance->requer_retorno);
        $this->assertSame('2026-08-17', $attendance->retorno_previsto_em?->toDateString());
    }

    public function test_foreign_relations_and_demand_from_another_citizen_are_rejected(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $citizen = Cidadao::factory()->forGabinete($office)->create();
        $otherCitizen = Cidadao::factory()->forGabinete($office)->create();
        $foreignCitizen = Cidadao::factory()->forGabinete($otherOffice)->create();
        $foreignUser = User::factory()->operator()->forGabinete($otherOffice)->create();
        $unrelatedDemand = Demanda::factory()
            ->forGabinete($office, $otherCitizen)
            ->create();

        $this->actingAs($user)
            ->post(route('attendances.store'), $this->payload([
                'cidadao_id' => $foreignCitizen->id,
                'atendente_id' => $foreignUser->id,
            ]))
            ->assertSessionHasErrors(['cidadao_id', 'atendente_id']);

        $this->post(route('attendances.store'), $this->payload([
            'cidadao_id' => $citizen->id,
            'atendente_id' => $user->id,
            'demanda_id' => $unrelatedDemand->id,
        ]))->assertSessionHasErrors('demanda_id');

        $this->assertDatabaseCount('atendimentos', 0);
    }

    public function test_only_office_managers_can_delete_attendances(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();
        $manager = User::factory()->administrator()->forGabinete($office)->create();
        $attendance = Atendimento::factory()
            ->forGabinete($office, attendant: $advisor)
            ->create();

        $this->actingAs($advisor)
            ->delete(route('attendances.destroy', $attendance))
            ->assertForbidden();
        $this->assertNotSoftDeleted($attendance);

        $this->actingAs($manager)
            ->delete(route('attendances.destroy', $attendance))
            ->assertRedirect(route('attendances.index'));
        $this->assertSoftDeleted($attendance);
    }

    public function test_citizen_profile_contains_recent_attendances(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $citizen = Cidadao::factory()->forGabinete($office)->create();
        $attendance = Atendimento::factory()
            ->forGabinete($office, $citizen, $user)
            ->create(['assunto' => 'Atendimento mais recente']);

        $this->actingAs($user)
            ->get(route('citizens.show', $citizen))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('citizens/show')
                ->where('attendanceSummary.total', 1)
                ->where('attendanceSummary.recent.0.id', $attendance->id)
                ->where('attendanceSummary.recent.0.assunto', 'Atendimento mais recente'));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'cidadao_id' => 1,
            'atendente_id' => 1,
            'demanda_id' => null,
            'assunto' => 'Atendimento de teste',
            'relato' => 'Relato suficientemente detalhado para o atendimento.',
            'providencias' => null,
            'atendido_em' => now()->subHour()->format('Y-m-d\TH:i'),
            'duracao_minutos' => 30,
            'requer_retorno' => false,
            'retorno_previsto_em' => null,
            ...$overrides,
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Enums\EventDuration;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Models\Cidadao;
use App\Models\Evento;
use App\Models\Gabinete;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_center_is_strictly_isolated_by_office(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $ownEvent = Evento::factory()->forGabinete($office)->create();
        $foreignEvent = Evento::factory()->forGabinete($otherOffice)->create();

        $this->actingAs($user)
            ->get(route('events.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('events/index')
                ->has('events.data', 1)
                ->where('events.data.0.id', $ownEvent->id));

        $this->get(route('events.show', $foreignEvent))
            ->assertNotFound();
    }

    public function test_citizen_participant_search_is_incremental_and_isolated_by_office(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $ownCitizen = Cidadao::factory()->forGabinete($office)->create([
            'nome' => 'Ana Maria da Silva',
        ]);
        Cidadao::factory()->forGabinete($otherOffice)->create([
            'nome' => 'Ana Maria de outro gabinete',
        ]);

        $this->actingAs($user)
            ->getJson(route('events.participants.citizens', ['q' => 'Ana']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ownCitizen->id)
            ->assertJsonPath('0.nome', 'Ana Maria da Silva');

        $this->getJson(route('events.participants.citizens', ['q' => 'A']))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_user_can_create_an_event_with_local_date_and_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10 15:00:00', 'UTC'));
        $office = Gabinete::factory()->create([
            'timezone' => 'America/Sao_Paulo',
        ]);
        $user = User::factory()->operator()->forGabinete($office)->create();
        $participant = User::factory()->operator()->forGabinete($office)->create();
        $citizen = Cidadao::factory()->forGabinete($office)->create();

        $this->actingAs($user)
            ->post(route('events.store'), [
                'titulo' => 'Assembleia comunitária',
                'tipo' => EventType::Assembly->value,
                'status' => EventStatus::Confirmed->value,
                'duracao' => EventDuration::SingleDay->value,
                'inicio_em' => '2026-08-15T18:30',
                'fim_em' => '2026-08-15T21:00',
                'local' => 'Auditório municipal',
                'responsavel_id' => $user->id,
                'participantes_usuarios' => [$participant->id],
                'participantes_cidadaos' => [$citizen->id],
                'descricao' => 'Assembleia aberta à comgabinete.',
                'observacoes' => 'Organizar lista de presença.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $event = Evento::withoutGlobalScopes()->sole();
        $this->assertSame($office->id, $event->gabinete_id);
        $this->assertSame($user->id, $event->criado_por_id);
        $this->assertSame($user->id, $event->responsavel_id);
        $this->assertSame(EventType::Assembly, $event->tipo);
        $this->assertSame(EventStatus::Confirmed, $event->status);
        $this->assertSame(EventDuration::SingleDay, $event->duracao);
        $this->assertSame('2026-08-15 21:30:00', $event->inicio_em->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-16 00:00:00', $event->fim_em->format('Y-m-d H:i:s'));
        $this->assertSame(
            [$participant->id],
            $event->participantesUsuarios()->pluck('users.id')->all(),
        );
        $this->assertSame(
            [$citizen->id],
            $event->participantesCidadaos()->pluck('cidadaos.id')->all(),
        );

        $this->actingAs($user)
            ->get(route('events.show', $event))
            ->assertInertia(fn (Assert $page) => $page
                ->has('event.participantes_usuarios', 1)
                ->where('event.participantes_usuarios.0.id', $participant->id)
                ->has('event.participantes_cidadaos', 1)
                ->where('event.participantes_cidadaos.0.id', $citizen->id));
    }

    public function test_end_must_be_after_start_and_responsible_from_same_office(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $foreignUser = User::factory()->operator()->forGabinete($otherOffice)->create();
        $foreignCitizen = Cidadao::factory()->forGabinete($otherOffice)->create();

        $this->actingAs($user)
            ->post(route('events.store'), $this->payload([
                'responsavel_id' => $foreignUser->id,
                'participantes_usuarios' => [$foreignUser->id],
                'participantes_cidadaos' => [$foreignCitizen->id],
                'inicio_em' => '2026-08-15T20:00',
                'fim_em' => '2026-08-15T19:00',
            ]))
            ->assertSessionHasErrors([
                'responsavel_id',
                'participantes_usuarios.0',
                'participantes_cidadaos.0',
                'fim_em',
            ]);

        $this->assertDatabaseCount('eventos', 0);
    }

    public function test_single_day_events_reject_another_date_and_multiple_day_events_use_daily_hours(): void
    {
        $office = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->operator()->forGabinete($office)->create();

        $this->actingAs($user)
            ->post(route('events.store'), $this->payload([
                'duracao' => EventDuration::SingleDay->value,
                'inicio_em' => '2026-08-15T09:00',
                'fim_em' => '2026-08-16T17:00',
            ]))
            ->assertSessionHasErrors('fim_em');

        $this->actingAs($user)
            ->post(route('events.store'), $this->payload([
                'duracao' => EventDuration::MultipleDays->value,
                'inicio_em' => '2026-08-15T18:00',
                'fim_em' => '2026-08-17T09:00',
            ]))
            ->assertSessionHasErrors('fim_em');

        $this->actingAs($user)
            ->post(route('events.store'), $this->payload([
                'duracao' => EventDuration::MultipleDays->value,
                'inicio_em' => '2026-08-15T09:00',
                'fim_em' => '2026-08-17T17:00',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $event = Evento::withoutGlobalScopes()->sole();
        $this->assertSame(EventDuration::MultipleDays, $event->duracao);
        $this->assertSame('2026-08-15 12:00:00', $event->inicio_em->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-17 20:00:00', $event->fim_em->format('Y-m-d H:i:s'));
    }

    public function test_only_office_managers_can_delete_events(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();
        $manager = User::factory()->administrator()->forGabinete($office)->create();
        $event = Evento::factory()->forGabinete($office, creator: $advisor)->create();

        $this->actingAs($advisor)
            ->delete(route('events.destroy', $event))
            ->assertForbidden();
        $this->assertNotSoftDeleted($event);

        $this->actingAs($manager)
            ->delete(route('events.destroy', $event))
            ->assertRedirect(route('events.index'));
        $this->assertSoftDeleted($event);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'titulo' => 'Reunião de planejamento',
            'tipo' => EventType::Meeting->value,
            'status' => EventStatus::Planned->value,
            'duracao' => EventDuration::SingleDay->value,
            'inicio_em' => '2026-08-15T09:00',
            'fim_em' => '2026-08-15T10:00',
            'local' => null,
            'responsavel_id' => null,
            'participantes_usuarios' => [],
            'participantes_cidadaos' => [],
            'descricao' => null,
            'observacoes' => null,
            ...$overrides,
        ];
    }
}

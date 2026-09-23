<?php

namespace Tests\Feature;

use App\Enums\AccessRole;
use App\Models\Demanda;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use App\Services\Demands\DemandNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * O alerta de prazo é o único aviso que não nasce de uma ação de alguém na
 * tela — ele só existe se o job agendado achar destinatário. Estes casos
 * cobrem justamente as demandas atrasadas que ficavam sem ninguém para
 * avisar e sumiam do sino sem erro nenhum.
 */
class DemandDeadlineNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_overdue_demand_notifies_the_responsible(): void
    {
        $office = Gabinete::factory()->create();
        $responsible = User::factory()->operator()->forGabinete($office)->create();
        $demand = $this->overdueDemand($office, $responsible);

        app(DemandNotificationService::class)->dispatchAttention();

        $this->assertNotified($responsible, $demand, 'Demanda atrasada');
    }

    public function test_overdue_demand_without_a_responsible_notifies_the_office_leadership(): void
    {
        $office = Gabinete::factory()->create();
        $leader = User::factory()->administrator()->forGabinete($office)->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();
        $demand = $this->overdueDemand($office);

        app(DemandNotificationService::class)->dispatchAttention();

        $this->assertNotified($leader, $demand, 'Demanda atrasada');
        $this->assertNotNotified($advisor, $demand);
    }

    /** Diretorias e secretarias não têm vereador nem chefe de gabinete. */
    public function test_overdue_demand_in_an_office_without_leadership_notifies_the_active_members(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();
        $inactive = User::factory()->operator()->inactive()->forGabinete($office)->create();
        $demand = $this->overdueDemand($office);

        app(DemandNotificationService::class)->dispatchAttention();

        $this->assertNotified($advisor, $demand, 'Demanda atrasada');
        $this->assertNotNotified($inactive, $demand);
    }

    public function test_responsible_linked_only_by_membership_still_receives_the_alert(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $responsible = User::factory()->operator()->forGabinete($otherOffice)->create();
        GabineteMembro::query()->create([
            'gabinete_id' => $office->id,
            'usuario_id' => $responsible->id,
            'papel' => AccessRole::Operator,
            'ativo' => true,
            'ingressou_em' => now(),
        ]);
        $demand = $this->overdueDemand($office, $responsible);

        app(DemandNotificationService::class)->dispatchAttention();

        $this->assertNotified($responsible, $demand, 'Demanda atrasada');
    }

    public function test_inactive_responsible_hands_the_alert_over_to_the_leadership(): void
    {
        $office = Gabinete::factory()->create();
        $leader = User::factory()->administrator()->forGabinete($office)->create();
        $responsible = User::factory()->operator()->inactive()->forGabinete($office)->create();
        $demand = $this->overdueDemand($office, $responsible);

        app(DemandNotificationService::class)->dispatchAttention();

        $this->assertNotified($leader, $demand, 'Demanda atrasada');
        $this->assertNotNotified($responsible, $demand);
    }

    public function test_overdue_next_action_without_an_assignee_notifies_the_leadership(): void
    {
        $office = Gabinete::factory()->create();
        $leader = User::factory()->administrator()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office)->create([
            'prazo' => null,
            'proxima_acao_descricao' => 'Cobrar retorno da secretaria',
            'proxima_acao_data' => now()->subDays(2),
            'proxima_acao_responsavel_id' => null,
            'proxima_acao_concluida_em' => null,
        ]);

        app(DemandNotificationService::class)->dispatchAttention();

        $this->assertNotified($leader, $demand, 'Próxima ação atrasada');
    }

    public function test_the_same_deadline_is_not_notified_twice(): void
    {
        $office = Gabinete::factory()->create();
        $responsible = User::factory()->operator()->forGabinete($office)->create();
        $this->overdueDemand($office, $responsible);

        $service = app(DemandNotificationService::class);
        $service->dispatchAttention();
        $service->dispatchAttention();

        $this->assertSame(1, DatabaseNotification::query()->count());
    }

    private function overdueDemand(Gabinete $office, ?User $responsible = null): Demanda
    {
        return Demanda::factory()->forGabinete($office)->create([
            'responsavel_id' => $responsible?->id,
            'prazo' => now()->subDays(3),
        ]);
    }

    private function assertNotified(User $user, Demanda $demand, string $title): void
    {
        $this->assertTrue(
            $this->notificationsFor($user, $demand)->contains(
                fn (DatabaseNotification $notification): bool => ($notification->data['title'] ?? null) === $title,
            ),
            "Esperava a notificação \"{$title}\" para {$user->name} na demanda {$demand->id}.",
        );
    }

    private function assertNotNotified(User $user, Demanda $demand): void
    {
        $this->assertTrue(
            $this->notificationsFor($user, $demand)->isEmpty(),
            "Não esperava notificação para {$user->name} na demanda {$demand->id}.",
        );
    }

    /** @return Collection<int, DatabaseNotification> */
    private function notificationsFor(User $user, Demanda $demand): Collection
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => ($notification->data['demand_id'] ?? null) === $demand->id);
    }
}

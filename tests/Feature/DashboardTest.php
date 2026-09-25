<?php

namespace Tests\Feature;

use App\Enums\AppointmentRecurrence;
use App\Enums\DemandStatus;
use App\Models\Appointment;
use App\Models\Demanda;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_platform_administrators_receive_a_safe_platform_dashboard(): void
    {
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/dashboard'));
    }

    public function test_dashboard_presents_operational_metrics_for_the_current_office_only(): void
    {
        Carbon::setTestNow('2026-07-23 12:00:00');
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->forGabinete($office)->create();

        Demanda::factory()->forGabinete($office)->create([
            'titulo' => 'Demanda vencida',
            'status' => DemandStatus::New,
            'aberta_em' => now()->subDays(10),
            'prazo' => now()->subDay(),
        ]);
        Demanda::factory()->forGabinete($office)->create([
            'titulo' => 'Demanda próxima do prazo',
            'status' => DemandStatus::InProgress,
            'aberta_em' => now()->subDays(5),
            'prazo' => now()->addDays(3),
        ]);
        Demanda::factory()->forGabinete($office)->create([
            'titulo' => 'Demanda resolvida',
            'status' => DemandStatus::Resolved,
            'aberta_em' => now()->subHours(48),
            'concluida_em' => now(),
            'prazo' => now()->addDay(),
        ]);
        Demanda::factory()->forGabinete($office)->create([
            'titulo' => 'Demanda encerrada',
            'status' => DemandStatus::Closed,
            'aberta_em' => now()->subDays(2),
            'concluida_em' => now(),
            'encerrada_em' => now(),
        ]);
        Demanda::factory()->forGabinete($otherOffice)->create([
            'titulo' => 'Demanda de outro gabinete',
            'status' => DemandStatus::New,
            'prazo' => now()->subDays(2),
        ]);
        Appointment::factory()->forGabinete($office)->create([
            'criado_por_id' => $user->id,
            'responsavel_id' => $user->id,
            'titulo' => 'Reunião de alinhamento',
            'inicio_em' => now()->addHour(),
            'fim_em' => now()->addHours(2),
        ]);
        Appointment::factory()->forGabinete($otherOffice)->create([
            'titulo' => 'Compromisso de outro gabinete',
            'inicio_em' => now()->addHour(),
            'fim_em' => now()->addHours(2),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard', ['period' => 30]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('filters.period', 30)
                ->where('metrics.open_total', 2)
                ->where('metrics.new', 1)
                ->where('metrics.in_progress', 1)
                ->where('metrics.overdue', 1)
                ->where('metrics.near_deadline', 1)
                ->where('metrics.resolved_month', 1)
                ->where('metrics.average_resolution_hours', 48)
                ->where('metrics.citizens', 4)
                ->has('upcomingAppointments', 1)
                ->where('upcomingAppointments.0.title', 'Reunião de alinhamento')
                ->where('upcomingAppointments.0.date_label', 'Hoje')
                ->has('recentDemands', 4)
                ->has('attentionDemands', 1)
                ->has('upcomingDeadlines', 1)
                ->where('attentionDemands.0.title', 'Demanda vencida')
                ->has('charts.status', 5)
                ->has('charts.origin', 7)
                ->has('charts.monthly'));
    }

    public function test_dashboard_includes_the_next_occurrence_of_a_recurring_appointment(): void
    {
        Carbon::setTestNow('2026-07-23 12:00:00');
        $office = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->forGabinete($office)->create();

        Appointment::factory()->forGabinete($office)->create([
            'criado_por_id' => $user->id,
            'responsavel_id' => $user->id,
            'titulo' => 'Reunião semanal',
            'inicio_em' => now()->subWeek()->addHour(),
            'fim_em' => now()->subWeek()->addHours(2),
            'recorrencia' => AppointmentRecurrence::Weekly,
            'recorrencia_ate' => now()->addMonth(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('upcomingAppointments', 2)
                ->where('upcomingAppointments.0.title', 'Reunião semanal')
                ->where('upcomingAppointments.0.date_label', 'Hoje'));
    }

    public function test_dashboard_period_filters_charts_and_recent_lists_but_keeps_the_current_snapshot(): void
    {
        Carbon::setTestNow('2026-07-23 12:00:00');
        $office = Gabinete::factory()->create();
        $user = User::factory()->forGabinete($office)->create();

        Demanda::factory()->forGabinete($office)->create([
            'titulo' => 'Registro recente',
            'status' => DemandStatus::New,
            'aberta_em' => now()->subDays(10),
        ]);
        Demanda::factory()->forGabinete($office)->create([
            'titulo' => 'Registro antigo',
            'status' => DemandStatus::New,
            'aberta_em' => now()->subDays(120),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard', ['period' => 30]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.open_total', 2)
                ->has('recentDemands', 1)
                ->where('recentDemands.0.title', 'Registro recente')
                ->where('charts.origin.0.total', 1));
    }

    public function test_dashboard_trends_compare_with_the_equivalent_previous_period(): void
    {
        // Período de 30 dias: começa em 24/06 00:00; o anterior equivalente
        // (mesma duração, 29,5 dias) vai de 25/05 12:00 até 24/06 00:00.
        Carbon::setTestNow('2026-07-23 12:00:00');
        $office = Gabinete::factory()->create();
        $user = User::factory()->forGabinete($office)->create();
        $demand = fn (array $attributes) => Demanda::factory()->forGabinete($office)->create([
            'status' => DemandStatus::New,
            'prazo' => null,
            ...$attributes,
        ]);

        // Aberta e atrasada agora; não existia no início do período.
        $demand(['aberta_em' => now()->subDays(10), 'prazo' => now()->subDay()]);
        // Aberta e atrasada tanto agora quanto no início do período.
        $demand(['aberta_em' => now()->subDays(60), 'prazo' => now()->subDays(40)]);
        // Aberta agora, sem prazo.
        $demand(['aberta_em' => now()->subDays(3)]);
        // Estava aberta no início (prazo na semana seguinte) e foi encerrada sem resolução depois.
        $demand([
            'status' => DemandStatus::Closed,
            'aberta_em' => now()->subDays(70),
            'prazo' => Carbon::parse('2026-06-27 12:00:00'),
            'encerrada_em' => now()->subDays(20),
        ]);
        // Resolvida no período anterior.
        $demand([
            'status' => DemandStatus::Resolved,
            'aberta_em' => now()->subDays(50),
            'concluida_em' => now()->subDays(40),
        ]);
        // Resolvidas no período atual.
        $demand(['status' => DemandStatus::Resolved, 'aberta_em' => now()->subDays(5), 'concluida_em' => now()->subDay()]);
        $demand(['status' => DemandStatus::Resolved, 'aberta_em' => now()->subDays(6), 'concluida_em' => now()->subDays(2)]);

        $this->actingAs($user)
            ->get(route('dashboard', ['period' => 30]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.open_total', 3)
                ->where('trends.open_total.current', 3)
                ->where('trends.open_total.previous', 2)
                ->has('trends.open_total.series', 12)
                ->where('trends.open_total.series.11', 3)
                ->where('trends.overdue.current', 2)
                ->where('trends.overdue.previous', 1)
                ->where('trends.near_deadline.current', 0)
                ->where('trends.near_deadline.previous', 1)
                ->where('metrics.resolved_period', 2)
                ->where('trends.resolved_period.current', 2)
                ->where('trends.resolved_period.previous', 1)
                ->has('trends.resolved_period.series', 12)
                ->where('trends.citizens.current', 7)
                ->where('trends.citizens.previous', 0)
                ->where('charts.monthly', fn ($months) => collect($months)->firstWhere('key', '2026-07')['resolved'] === 2));
    }

    public function test_dashboard_trends_without_data_are_zero(): void
    {
        Carbon::setTestNow('2026-07-23 12:00:00');
        $office = Gabinete::factory()->create();
        $user = User::factory()->forGabinete($office)->create();

        $this->actingAs($user)
            ->get(route('dashboard', ['period' => 90]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trends.open_total', ['current' => 0, 'previous' => 0, 'series' => array_fill(0, 12, 0)])
                ->where('trends.overdue.previous', 0)
                ->where('trends.near_deadline.previous', 0)
                ->where('trends.resolved_period', ['current' => 0, 'previous' => 0, 'series' => array_fill(0, 12, 0)])
                ->where('trends.citizens.current', 0));
    }

    public function test_dashboard_trends_ignore_other_offices(): void
    {
        Carbon::setTestNow('2026-07-23 12:00:00');
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->forGabinete($office)->create();

        Demanda::factory()->forGabinete($office)->create([
            'status' => DemandStatus::New,
            'aberta_em' => now()->subDays(60),
            'prazo' => now()->subDays(40),
        ]);
        Demanda::factory()->forGabinete($otherOffice)->count(2)->create([
            'status' => DemandStatus::New,
            'aberta_em' => now()->subDays(60),
            'prazo' => now()->subDays(40),
        ]);
        Demanda::factory()->forGabinete($otherOffice)->create([
            'status' => DemandStatus::Resolved,
            'aberta_em' => now()->subDays(50),
            'concluida_em' => now()->subDays(40),
        ]);
        Demanda::factory()->forGabinete($otherOffice)->create([
            'status' => DemandStatus::Resolved,
            'aberta_em' => now()->subDays(5),
            'concluida_em' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard', ['period' => 30]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trends.open_total.current', 1)
                ->where('trends.open_total.previous', 1)
                ->where('trends.overdue.previous', 1)
                ->where('trends.resolved_period.current', 0)
                ->where('trends.resolved_period.previous', 0)
                ->where('trends.citizens.current', 1)
                ->where('trends.citizens.previous', 0));
    }

    public function test_invalid_period_falls_back_to_ninety_days(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard', ['period' => 999]))
            ->assertInertia(fn (Assert $page) => $page->where('filters.period', 90));
    }
}

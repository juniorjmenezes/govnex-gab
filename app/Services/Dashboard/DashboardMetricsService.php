<?php

namespace App\Services\Dashboard;

use App\Enums\AppointmentRecurrence;
use App\Enums\AppointmentStatus;
use App\Enums\DemandOrigin;
use App\Enums\DemandStatus;
use App\Enums\GabineteModule;
use App\Models\Appointment;
use App\Models\Bairro;
use App\Models\Categoria;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\DemandaEvento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardMetricsService
{
    /** Pontos da série curta (sparkline) de cada indicador. */
    private const SPARKLINE_POINTS = 12;

    /** @var list<string> */
    private array $openStatuses;

    public function __construct()
    {
        $this->openStatuses = DemandStatus::openValues();
    }

    /** @param list<string> $activeModules
     * @return array<string, mixed>
     */
    public function build(
        int $period,
        string $timezone = 'America/Sao_Paulo',
        array $activeModules = [],
    ): array {
        $now = CarbonImmutable::now();
        $start = $now->subDays($period - 1)->startOfDay();
        $capabilities = [
            'relationship' => in_array(GabineteModule::Relationship->value, $activeModules, true),
            'demands' => in_array(GabineteModule::Demands->value, $activeModules, true),
            'schedule' => in_array(GabineteModule::Schedule->value, $activeModules, true),
            'politics' => in_array(GabineteModule::Politics->value, $activeModules, true),
            'whatsapp' => in_array(GabineteModule::WhatsApp->value, $activeModules, true),
        ];

        if (! $capabilities['demands']) {
            return [
                'filters' => [
                    'period' => $period,
                    'start' => $start->toIso8601String(),
                    'end' => $now->toIso8601String(),
                ],
                'periodOptions' => $this->periodOptions(),
                'capabilities' => $capabilities,
                'metrics' => [
                    'open_total' => 0,
                    'new' => 0,
                    'in_progress' => 0,
                    'awaiting' => 0,
                    'for_today' => 0,
                    'overdue' => 0,
                    'resolved_month' => 0,
                    'citizens' => $capabilities['relationship'] ? Cidadao::query()->count() : 0,
                    'average_resolution_hours' => null,
                    'near_deadline' => 0,
                    'resolved_period' => 0,
                ],
                'trends' => $capabilities['relationship']
                    ? ['citizens' => $this->citizenTrend($start, $now)]
                    : [],
                'charts' => [
                    'status' => [],
                    'category' => [],
                    'neighborhood' => [],
                    'responsible' => [],
                    'origin' => [],
                    'monthly' => [],
                ],
                'upcomingAppointments' => $capabilities['schedule']
                    ? $this->upcomingAppointments($timezone)
                    : [],
                'recentDemands' => [],
                'attentionDemands' => [],
                'upcomingDeadlines' => [],
                'recentActivity' => [],
            ];
        }

        $periodQuery = fn (): Builder => Demanda::query()->where('aberta_em', '>=', $start);
        $statusCounts = Demanda::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $overdueQuery = fn (): Builder => Demanda::query()
            ->whereIn('status', $this->openStatuses)
            ->whereNotNull('prazo')
            ->where('prazo', '<', $now);

        $upcomingQuery = fn (): Builder => Demanda::query()
            ->whereIn('status', $this->openStatuses)
            ->whereBetween('prazo', [$now, $now->addDays(7)]);

        $openTotal = collect($this->openStatuses)->sum(fn (string $status): int => (int) ($statusCounts[$status] ?? 0));
        $overdue = $overdueQuery()->count();
        $nearDeadline = $upcomingQuery()->count();
        $trends = $this->demandTrends($start, $now, [
            'open_total' => $openTotal,
            'overdue' => $overdue,
            'near_deadline' => $nearDeadline,
        ]);

        if ($capabilities['relationship']) {
            $trends['citizens'] = $this->citizenTrend($start, $now);
        }

        return [
            'filters' => [
                'period' => $period,
                'start' => $start->toIso8601String(),
                'end' => $now->toIso8601String(),
            ],
            'periodOptions' => collect([30, 90, 180, 365])
                ->map(fn (int $days): array => ['value' => $days, 'label' => "Últimos {$days} dias"]),
            'capabilities' => $capabilities,
            'metrics' => [
                'open_total' => $openTotal,
                'new' => (int) ($statusCounts[DemandStatus::New->value] ?? 0),
                'in_progress' => (int) ($statusCounts[DemandStatus::InProgress->value] ?? 0),
                'awaiting' => (int) ($statusCounts[DemandStatus::Awaiting->value] ?? 0),
                'for_today' => Demanda::query()
                    ->whereNull('proxima_acao_concluida_em')
                    ->whereDate('proxima_acao_data', $now->toDateString())
                    ->count(),
                'overdue' => $overdue,
                'resolved_month' => Demanda::query()
                    ->where('status', DemandStatus::Resolved)
                    ->whereBetween('concluida_em', [$now->startOfMonth(), $now])
                    ->count(),
                'citizens' => Cidadao::query()->count(),
                'average_resolution_hours' => $this->averageResolutionHours($start),
                'near_deadline' => $nearDeadline,
                'resolved_period' => $trends['resolved_period']['current'],
            ],
            'trends' => $trends,
            'charts' => [
                'status' => collect(DemandStatus::cases())->map(fn (DemandStatus $status): array => [
                    'key' => $status->value,
                    'label' => $status->label(),
                    'total' => (int) ($statusCounts[$status->value] ?? 0),
                ]),
                'category' => $this->relationDistribution($periodQuery(), 'categoria_id', Categoria::class, 'nome'),
                'neighborhood' => $this->relationDistribution($periodQuery(), 'bairro_id', Bairro::class, 'nome', 'Não informado'),
                'responsible' => $this->relationDistribution($periodQuery(), 'responsavel_id', User::class, 'name', 'Não atribuído'),
                'origin' => $this->enumDistribution($periodQuery(), 'origem', DemandOrigin::cases()),
                'monthly' => $this->monthlyEvolution($start, $now),
            ],
            'upcomingAppointments' => $capabilities['schedule']
                ? $this->upcomingAppointments($timezone)
                : [],
            'recentDemands' => $this->demandCards($periodQuery()->latest('aberta_em')->limit(6)->get()),
            'attentionDemands' => $this->demandCards($overdueQuery()
                ->orderByRaw("CASE prioridade WHEN 'urgente' THEN 1 WHEN 'alta' THEN 2 ELSE 3 END")
                ->orderBy('prazo')
                ->limit(6)
                ->get()),
            'upcomingDeadlines' => $this->demandCards($upcomingQuery()->orderBy('prazo')->limit(6)->get()),
            'recentActivity' => DemandaEvento::query()
                ->where('created_at', '>=', $start)
                ->with(['demanda:id,protocolo,titulo', 'usuario:id,name'])
                ->latest('created_at')
                ->limit(8)
                ->get()
                ->map(fn (DemandaEvento $event): array => [
                    'id' => $event->id,
                    'event' => $event->tipo->value,
                    'description' => $event->descricao ?? $event->tipo->label(),
                    'created_at' => $event->created_at->toIso8601String(),
                    'user' => $event->usuario ? ['id' => $event->usuario->id, 'name' => $event->usuario->name] : null,
                    'demand' => $event->demanda ? [
                        'id' => $event->demanda->id,
                        'protocol' => $event->demanda->protocolo,
                        'title' => $event->demanda->titulo,
                    ] : null,
                ]),
        ];
    }

    /** @return Collection<int, array{value: int, label: string}> */
    private function periodOptions(): Collection
    {
        return collect([30, 90, 180, 365])
            ->map(fn (int $days): array => ['value' => $days, 'label' => "Últimos {$days} dias"]);
    }

    /** @return array<int, array<string, mixed>> */
    private function upcomingAppointments(string $timezone): array
    {
        $localNow = CarbonImmutable::now($timezone);
        $rangeStart = $localNow->utc();
        $rangeEnd = $localNow->addDays(7)->endOfDay()->utc();

        return Appointment::query()
            ->whereIn('status', [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed])
            ->where('inicio_em', '<=', $rangeEnd)
            ->where(function (Builder $query) use ($rangeStart, $localNow): void {
                $query->where(function (Builder $single) use ($rangeStart): void {
                    $single->where('recorrencia', AppointmentRecurrence::None)
                        ->where('fim_em', '>=', $rangeStart);
                })->orWhere(function (Builder $recurring) use ($localNow): void {
                    $recurring->where('recorrencia', '!=', AppointmentRecurrence::None)
                        ->where(function (Builder $until) use ($localNow): void {
                            $until->whereNull('recorrencia_ate')
                                ->orWhereDate('recorrencia_ate', '>=', $localNow->toDateString());
                        });
                });
            })
            ->with('responsavel:id,name')
            ->orderBy('inicio_em')
            ->limit(100)
            ->get()
            ->flatMap(fn (Appointment $appointment): array => $this->appointmentOccurrences(
                $appointment,
                $rangeStart,
                $rangeEnd,
                $timezone,
            ))
            ->sortBy('starts_at')
            ->take(4)
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function appointmentOccurrences(
        Appointment $appointment,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        string $timezone,
    ): array {
        $seriesStart = CarbonImmutable::instance($appointment->inicio_em);
        $seriesEnd = CarbonImmutable::instance($appointment->fim_em);

        if ($appointment->recorrencia === AppointmentRecurrence::None) {
            return $seriesEnd->gte($rangeStart) && $seriesStart->lte($rangeEnd)
                ? [$this->appointmentCard($appointment, $seriesStart, $timezone)]
                : [];
        }

        $duration = $seriesStart->diffInSeconds($seriesEnd);
        $cursor = $this->fastForwardOccurrence($appointment->recorrencia, $seriesStart, $seriesEnd, $rangeStart);
        $until = $appointment->recorrencia_ate
            ? CarbonImmutable::instance($appointment->recorrencia_ate)->endOfDay()
            : $rangeEnd;
        $items = [];

        while ($cursor->lte($rangeEnd) && $cursor->lte($until) && count($items) < 8) {
            if ($cursor->addSeconds($duration)->gte($rangeStart)) {
                $items[] = $this->appointmentCard($appointment, $cursor, $timezone);
            }

            $cursor = match ($appointment->recorrencia) {
                AppointmentRecurrence::Daily => $cursor->addDay(),
                AppointmentRecurrence::Weekly => $cursor->addWeek(),
                AppointmentRecurrence::Monthly => $cursor->addMonthNoOverflow(),
            };
        }

        return $items;
    }

    private function fastForwardOccurrence(
        AppointmentRecurrence $recurrence,
        CarbonImmutable $seriesStart,
        CarbonImmutable $seriesEnd,
        CarbonImmutable $rangeStart,
    ): CarbonImmutable {
        if ($seriesEnd->gte($rangeStart)) {
            return $seriesStart;
        }

        $secondsBehind = max(0, $rangeStart->getTimestamp() - $seriesEnd->getTimestamp());
        $cursor = match ($recurrence) {
            AppointmentRecurrence::Daily => $seriesStart->addDays(max(0, intdiv($secondsBehind, 86400) - 1)),
            AppointmentRecurrence::Weekly => $seriesStart->addWeeks(max(0, intdiv($secondsBehind, 604800) - 1)),
            AppointmentRecurrence::Monthly => $seriesStart->addMonthsNoOverflow(max(
                0,
                (($rangeStart->year - $seriesStart->year) * 12) + $rangeStart->month - $seriesStart->month - 1,
            )),
            AppointmentRecurrence::None => $seriesStart,
        };

        while ($cursor->addSeconds($seriesStart->diffInSeconds($seriesEnd))->lt($rangeStart)) {
            $cursor = match ($recurrence) {
                AppointmentRecurrence::Daily => $cursor->addDay(),
                AppointmentRecurrence::Weekly => $cursor->addWeek(),
                AppointmentRecurrence::Monthly => $cursor->addMonthNoOverflow(),
                AppointmentRecurrence::None => $rangeStart,
            };
        }

        return $cursor;
    }

    /** @return array<string, mixed> */
    private function appointmentCard(
        Appointment $appointment,
        CarbonImmutable $start,
        string $timezone,
    ): array {
        $localStart = $start->setTimezone($timezone);
        $localToday = CarbonImmutable::now($timezone)->startOfDay();
        $dayLabel = match (true) {
            $localStart->isSameDay($localToday) => 'Hoje',
            $localStart->isSameDay($localToday->addDay()) => 'Amanhã',
            default => ucfirst($localStart->translatedFormat('D, d M')),
        };

        return [
            'id' => $appointment->id,
            'title' => $appointment->titulo,
            'starts_at' => $start->toIso8601String(),
            'date' => $localStart->toDateString(),
            'date_label' => $dayLabel,
            'time_label' => $appointment->dia_inteiro ? 'Dia inteiro' : $localStart->format('H:i'),
            'location' => $appointment->local,
            'status' => $appointment->status->value,
            'status_label' => $appointment->status->label(),
            'responsible' => $appointment->responsavel
                ? ['id' => $appointment->responsavel->id, 'name' => $appointment->responsavel->name]
                : null,
        ];
    }

    /**
     * Marcos da série curta dos indicadores: o início do período e mais
     * SPARKLINE_POINTS instantes igualmente espaçados até agora (o último é
     * `$now`). O período anterior equivalente é o intervalo de mesma duração
     * que termina em `$start`.
     *
     * @return list<CarbonImmutable>
     */
    private function trendEdges(CarbonImmutable $start, CarbonImmutable $now): array
    {
        $duration = max(1, $now->getTimestamp() - $start->getTimestamp());
        $edges = [];

        for ($index = 0; $index <= self::SPARKLINE_POINTS; $index++) {
            $edges[] = $index === self::SPARKLINE_POINTS
                ? $now
                : $start->addSeconds(intdiv($duration * $index, self::SPARKLINE_POINTS));
        }

        return $edges;
    }

    /**
     * Tendências dos indicadores de demandas numa única consulta agregada
     * (respeita o escopo de gabinete do model e ignora excluídas).
     *
     * - Estoques (abertas, atrasadas, vencem em 7 dias) são reconstruídos em
     *   cada marco: aberta até o instante e ainda não concluída/encerrada nele.
     *   `previous` é o estoque no início do período (fim do período anterior).
     * - Fluxo (resolvidas) conta `concluida_em` em cada intervalo; `previous`
     *   é o total no intervalo de mesma duração imediatamente anterior.
     *
     * Reaberturas limpam `concluida_em`/`encerrada_em`, então uma demanda
     * reaberta conta como aberta desde a abertura original: a série é uma
     * aproximação histórica, e o último ponto dos estoques é sempre o valor
     * atual exibido no cartão.
     *
     * @param  array{open_total: int, overdue: int, near_deadline: int}  $current
     * @return array<string, array{current: int, previous: int|null, series: list<int>}>
     */
    private function demandTrends(CarbonImmutable $start, CarbonImmutable $now, array $current): array
    {
        $edges = $this->trendEdges($start, $now);
        $previousStart = $start->subSeconds($now->getTimestamp() - $start->getTimestamp());
        $closedAt = 'COALESCE(concluida_em, encerrada_em)';
        $columns = [];
        $bindings = [];
        $at = fn (CarbonImmutable $moment): string => $moment->format('Y-m-d H:i:s');

        foreach ($edges as $index => $edge) {
            $openAt = "aberta_em <= ? AND ({$closedAt} IS NULL OR {$closedAt} > ?)";

            $columns[] = "SUM(CASE WHEN {$openAt} THEN 1 ELSE 0 END) AS open_{$index}";
            array_push($bindings, $at($edge), $at($edge));

            $columns[] = "SUM(CASE WHEN {$openAt} AND prazo IS NOT NULL AND prazo < ? THEN 1 ELSE 0 END) AS overdue_{$index}";
            array_push($bindings, $at($edge), $at($edge), $at($edge));

            $columns[] = "SUM(CASE WHEN {$openAt} AND prazo >= ? AND prazo <= ? THEN 1 ELSE 0 END) AS near_{$index}";
            array_push($bindings, $at($edge), $at($edge), $at($edge), $at($edge->addDays(7)));

            $from = $index === 0 ? $previousStart : $edges[$index - 1];
            $upperOperator = $index === self::SPARKLINE_POINTS ? '<=' : '<';
            $columns[] = "SUM(CASE WHEN concluida_em >= ? AND concluida_em {$upperOperator} ? THEN 1 ELSE 0 END) AS resolved_{$index}";
            array_push($bindings, $at($from), $at($edge));
        }

        $row = (array) Demanda::query()->toBase()->selectRaw(implode(', ', $columns), $bindings)->first();
        $value = fn (string $key): int => (int) ($row[$key] ?? 0);
        $points = range(1, self::SPARKLINE_POINTS);
        $stock = function (string $prefix, int $currentValue) use ($value, $points): array {
            $series = array_map(fn (int $index): int => $value("{$prefix}_{$index}"), $points);
            $series[array_key_last($series)] = $currentValue;

            return ['current' => $currentValue, 'previous' => $value("{$prefix}_0"), 'series' => $series];
        };
        $resolvedSeries = array_map(fn (int $index): int => $value("resolved_{$index}"), $points);

        return [
            'open_total' => $stock('open', $current['open_total']),
            'overdue' => $stock('overdue', $current['overdue']),
            'near_deadline' => $stock('near', $current['near_deadline']),
            'resolved_period' => [
                'current' => array_sum($resolvedSeries),
                'previous' => $value('resolved_0'),
                'series' => $resolvedSeries,
            ],
        ];
    }

    /** @return array{current: int, previous: int|null, series: list<int>} */
    private function citizenTrend(CarbonImmutable $start, CarbonImmutable $now): array
    {
        $edges = $this->trendEdges($start, $now);
        $columns = [];
        $bindings = [];

        foreach ($edges as $index => $edge) {
            $columns[] = "SUM(CASE WHEN created_at <= ? THEN 1 ELSE 0 END) AS total_{$index}";
            $bindings[] = $edge->format('Y-m-d H:i:s');
        }

        $row = (array) Cidadao::query()->toBase()->selectRaw(implode(', ', $columns), $bindings)->first();
        $current = Cidadao::query()->count();
        $series = array_map(fn (int $index): int => (int) ($row["total_{$index}"] ?? 0), range(1, self::SPARKLINE_POINTS));
        $series[array_key_last($series)] = $current;

        return ['current' => $current, 'previous' => (int) ($row['total_0'] ?? 0), 'series' => $series];
    }

    private function averageResolutionHours(CarbonImmutable $start): ?float
    {
        $query = Demanda::query()
            ->whereNotNull('concluida_em')
            ->where('concluida_em', '>=', $start);

        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? 'AVG((julianday(concluida_em) - julianday(aberta_em)) * 24)'
            : 'AVG(TIMESTAMPDIFF(SECOND, aberta_em, concluida_em) / 3600)';

        $average = $query->selectRaw("{$expression} as average_hours")->value('average_hours');

        return $average === null ? null : round((float) $average, 1);
    }

    /**
     * @param  Builder<Demanda>  $query
     * @param  class-string<Categoria|Bairro|User>  $model
     * @return Collection<int, array{key: string, label: string, total: int}>
     */
    private function relationDistribution(
        Builder $query,
        string $foreignKey,
        string $model,
        string $labelColumn,
        string $nullLabel = 'Não informado',
    ): Collection {
        $rows = $query->select([$foreignKey])->selectRaw('COUNT(*) as total')
            ->groupBy($foreignKey)
            ->orderByDesc('total')
            ->limit(8)
            ->get();
        $ids = $rows->pluck($foreignKey)->filter()->values();
        $labels = $model::query()->whereIn('id', $ids)->pluck($labelColumn, 'id');

        return $rows->map(fn (Demanda $row): array => [
            'key' => (string) ($row->getAttribute($foreignKey) ?? 'none'),
            'label' => (string) ($labels[$row->getAttribute($foreignKey)] ?? $nullLabel),
            'total' => (int) $row->getAttribute('total'),
        ]);
    }

    /**
     * @param  Builder<Demanda>  $query
     * @param  array<int, DemandOrigin>  $cases
     * @return Collection<int, array{key: string, label: string, total: int}>
     */
    private function enumDistribution(Builder $query, string $column, array $cases): Collection
    {
        $counts = $query->select([$column])->selectRaw('COUNT(*) as total')->groupBy($column)->pluck('total', $column);

        return collect($cases)->map(fn (DemandOrigin $case): array => [
            'key' => $case->value,
            'label' => $case->label(),
            'total' => (int) ($counts[$case->value] ?? 0),
        ]);
    }

    /** @return Collection<int, array{key: string, label: string, total: int, resolved: int}> */
    private function monthlyEvolution(CarbonImmutable $start, CarbonImmutable $now): Collection
    {
        $chartStart = $start->max($now->subMonths(11)->startOfMonth())->startOfMonth();
        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', aberta_em)"
            : "DATE_FORMAT(aberta_em, '%Y-%m')";
        $counts = Demanda::query()
            ->where('aberta_em', '>=', $chartStart)
            ->selectRaw("{$expression} as month_key, COUNT(*) as total")
            ->groupBy('month_key')
            ->pluck('total', 'month_key');
        $resolvedExpression = str_replace('aberta_em', 'concluida_em', $expression);
        $resolvedCounts = Demanda::query()
            ->where('concluida_em', '>=', $chartStart)
            ->selectRaw("{$resolvedExpression} as month_key, COUNT(*) as total")
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        $months = collect();
        for ($month = $chartStart; $month->lte($now); $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $months->push([
                'key' => $key,
                'label' => $this->monthLabel($month),
                'total' => (int) ($counts[$key] ?? 0),
                'resolved' => (int) ($resolvedCounts[$key] ?? 0),
            ]);
        }

        return $months;
    }

    /**
     * @param  EloquentCollection<int, Demanda>  $demands
     * @return array<int, array<string, mixed>>
     */
    private function demandCards(EloquentCollection $demands): array
    {
        $demands->loadMissing([
            'cidadao:id,nome',
            'categoria:id,nome,cor_semantica',
            'responsavel:id,name',
        ]);

        return $demands->map(fn (Demanda $demand): array => [
            'id' => $demand->id,
            'protocol' => $demand->protocolo,
            'title' => $demand->titulo,
            'status' => $demand->status->value,
            'priority' => $demand->prioridade->value,
            'opened_at' => $demand->aberta_em->toIso8601String(),
            'deadline' => $demand->prazo?->toIso8601String(),
            'overdue' => $demand->isOverdue(),
            'citizen' => $demand->cidadao ? ['id' => $demand->cidadao->id, 'name' => $demand->cidadao->nome] : null,
            'category' => $demand->categoria ? ['id' => $demand->categoria->id, 'name' => $demand->categoria->nome] : null,
            'responsible' => $demand->responsavel ? ['id' => $demand->responsavel->id, 'name' => $demand->responsavel->name] : null,
        ])->all();
    }

    private function monthLabel(CarbonImmutable $month): string
    {
        $labels = [1 => 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

        return $labels[$month->month].'/'.$month->format('y');
    }
}

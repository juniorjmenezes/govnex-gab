<?php

namespace App\Services\Reports;

use App\Enums\DemandEventType;
use App\Enums\DemandOrigin;
use App\Enums\DemandPriority;
use App\Enums\DemandStatus;
use App\Models\Bairro;
use App\Models\Categoria;
use App\Models\Demanda;
use App\Models\DemandaEvento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DemandReportService
{
    /** @var list<string> */
    private array $openStatuses = [];

    public function __construct()
    {
        $this->openStatuses = DemandStatus::openValues();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function screen(int $gabineteId, array $filters): array
    {
        $query = $this->query($gabineteId, $filters);

        return [
            ...$this->analytics($gabineteId, $filters),
            'demands' => $this->paginatedDemands($query),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function export(int $gabineteId, array $filters): array
    {
        $query = $this->query($gabineteId, $filters);

        return [
            ...$this->analytics($gabineteId, $filters),
            'demands' => $this->demandRows($this->demandQuery($query)->orderByDesc('aberta_em')->get()),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function analytics(int $gabineteId, array $filters): array
    {
        $query = $this->query($gabineteId, $filters);
        $statusCounts = (clone $query)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $total = (int) $statusCounts->sum();
        $resolved = (int) ($statusCounts[DemandStatus::Resolved->value] ?? 0);
        $closed = (int) ($statusCounts[DemandStatus::Closed->value] ?? 0);
        $overdue = (clone $query)
            ->whereIn('status', $this->openStatuses)
            ->whereNotNull('prazo')
            ->where('prazo', '<', now())
            ->count();
        $demandIds = (clone $query)->select('id');
        $waitingReferralQuery = DemandaEvento::withoutGlobalScopes()
            ->where('gabinete_id', $gabineteId)
            ->where('tipo', DemandEventType::Encaminhamento)
            ->whereNull('retorno_recebido_em')
            ->whereIn('demanda_id', $demandIds);

        return [
            'summary' => [
                'total' => $total,
                'open' => collect($this->openStatuses)->sum(fn (string $status): int => (int) ($statusCounts[$status] ?? 0)),
                'resolved' => $resolved,
                'closed' => $closed,
                'overdue' => $overdue,
                'average_resolution_hours' => $this->averageResolutionHours($query),
                'resolution_rate' => $total > 0 ? round(($resolved / $total) * 100, 1) : 0.0,
                'waiting_referrals' => (clone $waitingReferralQuery)->count(),
            ],
            'charts' => [
                'status' => $this->enumDistribution($statusCounts, DemandStatus::cases()),
                'priority' => $this->distribution($query, 'prioridade', DemandPriority::cases()),
                'origin' => $this->distribution($query, 'origem', DemandOrigin::cases()),
                'category' => $this->relationDistribution($query, 'categoria_id', Categoria::class, 'nome', $gabineteId),
                'neighborhood' => $this->relationDistribution($query, 'bairro_id', Bairro::class, 'nome', $gabineteId, 'Não informado'),
                'responsible' => $this->relationDistribution($query, 'responsavel_id', User::class, 'name', $gabineteId, 'Não atribuído'),
                'monthly' => $this->monthly($query, (string) $filters['inicio'], (string) $filters['fim']),
            ],
            'productivity' => $this->productivity($query, $gabineteId),
            'waitingReferrals' => (clone $waitingReferralQuery)
                ->with(['demanda' => fn ($demand) => $demand->withoutGlobalScopes()->select(['id', 'protocolo', 'titulo'])])
                ->orderByRaw('prazo_esperado IS NULL')
                ->orderBy('prazo_esperado')
                ->limit(20)
                ->get()
                ->map(fn (DemandaEvento $referral): array => [
                    'id' => $referral->id,
                    'recipient' => $referral->destino,
                    'deadline' => $referral->prazo_esperado?->toDateString(),
                    'overdue' => $referral->prazo_esperado?->isPast() ?? false,
                    'demand' => $referral->demanda ? [
                        'id' => $referral->demanda->id,
                        'protocol' => $referral->demanda->protocolo,
                        'title' => $referral->demanda->titulo,
                    ] : null,
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Demanda>
     */
    private function query(int $gabineteId, array $filters): Builder
    {
        return Demanda::withoutGlobalScopes()
            ->where('gabinete_id', $gabineteId)
            ->whereBetween('aberta_em', [
                CarbonImmutable::parse((string) $filters['inicio'])->startOfDay(),
                CarbonImmutable::parse((string) $filters['fim'])->endOfDay(),
            ])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['prioridade'] ?? null, fn (Builder $query, string $priority) => $query->where('prioridade', $priority))
            ->when($filters['origem'] ?? null, fn (Builder $query, string $origin) => $query->where('origem', $origin))
            ->when($filters['categoria_id'] ?? null, fn (Builder $query, int $category) => $query->where('categoria_id', $category))
            ->when($filters['bairro_id'] ?? null, fn (Builder $query, int $neighborhood) => $query->where('bairro_id', $neighborhood))
            ->when($filters['responsavel_id'] ?? null, fn (Builder $query, int $responsible) => $query->where('responsavel_id', $responsible))
            ->when($filters['atrasadas'] ?? false, fn (Builder $query) => $query
                ->whereIn('status', $this->openStatuses)
                ->whereNotNull('prazo')
                ->where('prazo', '<', now()));
    }

    /** @param Builder<Demanda> $query */
    private function averageResolutionHours(Builder $query): ?float
    {
        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? 'AVG((julianday(concluida_em) - julianday(aberta_em)) * 24)'
            : 'AVG(TIMESTAMPDIFF(SECOND, aberta_em, concluida_em) / 3600)';
        $average = (clone $query)
            ->whereNotNull('concluida_em')
            ->selectRaw("{$expression} as average_hours")
            ->value('average_hours');

        return $average === null ? null : round((float) $average, 1);
    }

    /**
     * @param  Collection<string, int|string>  $counts
     * @param  array<int, DemandStatus>  $cases
     * @return Collection<int, array{key: string, label: string, total: int}>
     */
    private function enumDistribution(Collection $counts, array $cases): Collection
    {
        return collect($cases)->map(fn (DemandStatus $case): array => [
            'key' => $case->value,
            'label' => $case->label(),
            'total' => (int) ($counts[$case->value] ?? 0),
        ]);
    }

    /**
     * @param  Builder<Demanda>  $query
     * @param  array<int, DemandPriority>|array<int, DemandOrigin>  $cases
     * @return Collection<int, array{key: string, label: string, total: int}>
     */
    private function distribution(Builder $query, string $column, array $cases): Collection
    {
        $counts = (clone $query)->select([$column])->selectRaw('COUNT(*) as total')->groupBy($column)->pluck('total', $column);

        return collect($cases)->map(fn (DemandPriority|DemandOrigin $case): array => [
            'key' => $case->value,
            'label' => $case->label(),
            'total' => (int) ($counts[$case->value] ?? 0),
        ]);
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
        int $gabineteId,
        string $nullLabel = 'Não informado',
    ): Collection {
        $rows = (clone $query)
            ->select([$foreignKey])
            ->selectRaw('COUNT(*) as total')
            ->groupBy($foreignKey)
            ->orderByDesc('total')
            ->limit(12)
            ->get();
        $labels = $model::withoutGlobalScopes()
            ->where('gabinete_id', $gabineteId)
            ->whereIn('id', $rows->pluck($foreignKey)->filter())
            ->pluck($labelColumn, 'id');

        return $rows->map(fn (Demanda $row): array => [
            'key' => (string) ($row->getAttribute($foreignKey) ?? 'none'),
            'label' => (string) ($labels[$row->getAttribute($foreignKey)] ?? $nullLabel),
            'total' => (int) $row->getAttribute('total'),
        ]);
    }

    /**
     * @param  Builder<Demanda>  $query
     * @return Collection<int, array{key: string, label: string, total: int}>
     */
    private function monthly(Builder $query, string $start, string $end): Collection
    {
        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', aberta_em)"
            : "DATE_FORMAT(aberta_em, '%Y-%m')";
        $counts = (clone $query)
            ->selectRaw("{$expression} as month_key, COUNT(*) as total")
            ->groupBy('month_key')
            ->pluck('total', 'month_key');
        $cursor = CarbonImmutable::parse($start)->startOfMonth();
        $last = CarbonImmutable::parse($end)->startOfMonth();
        $result = collect();
        $labels = [1 => 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

        while ($cursor->lte($last)) {
            $key = $cursor->format('Y-m');
            $result->push([
                'key' => $key,
                'label' => $labels[$cursor->month].'/'.$cursor->format('y'),
                'total' => (int) ($counts[$key] ?? 0),
            ]);
            $cursor = $cursor->addMonth();
        }

        return $result;
    }

    /**
     * @param  Builder<Demanda>  $query
     * @return array<int, array{id: int|null, name: string, assigned: int, resolved: int, average_resolution_hours: float|null}>
     */
    private function productivity(Builder $query, int $gabineteId): array
    {
        $averageExpression = DB::connection()->getDriverName() === 'sqlite'
            ? 'AVG(CASE WHEN concluida_em IS NOT NULL THEN (julianday(concluida_em) - julianday(aberta_em)) * 24 END)'
            : 'AVG(CASE WHEN concluida_em IS NOT NULL THEN TIMESTAMPDIFF(SECOND, aberta_em, concluida_em) / 3600 END)';
        $rows = (clone $query)
            ->select('responsavel_id')
            ->selectRaw('COUNT(*) as assigned')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as resolved', [DemandStatus::Resolved->value])
            ->selectRaw("{$averageExpression} as average_hours")
            ->groupBy('responsavel_id')
            ->orderByDesc('resolved')
            ->orderByDesc('assigned')
            ->get();
        $names = User::withoutGlobalScopes()
            ->where('gabinete_id', $gabineteId)
            ->whereIn('id', $rows->pluck('responsavel_id')->filter())
            ->pluck('name', 'id');

        return $rows->map(fn (Demanda $row): array => [
            'id' => $row->responsavel_id,
            'name' => (string) ($names[$row->responsavel_id] ?? 'Não atribuído'),
            'assigned' => (int) $row->getAttribute('assigned'),
            'resolved' => (int) $row->getAttribute('resolved'),
            'average_resolution_hours' => $row->getAttribute('average_hours') === null
                ? null
                : round((float) $row->getAttribute('average_hours'), 1),
        ])->all();
    }

    /**
     * @param  Builder<Demanda>  $query
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginatedDemands(Builder $query): LengthAwarePaginator
    {
        return $this->demandQuery($query)
            ->orderByDesc('aberta_em')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Demanda $demand): array => $this->demandRow($demand));
    }

    /**
     * @param  Builder<Demanda>  $query
     * @return Builder<Demanda>
     */
    private function demandQuery(Builder $query): Builder
    {
        return $query
            ->select([
                'id', 'protocolo', 'titulo', 'status', 'prioridade', 'origem',
                'cidadao_id', 'categoria_id', 'bairro_id', 'responsavel_id',
                'aberta_em', 'prazo', 'concluida_em',
            ])
            ->with([
                'cidadao:id,nome',
                'categoria:id,nome',
                'bairro:id,nome',
                'responsavel:id,name',
            ]);
    }

    /**
     * @param  EloquentCollection<int, Demanda>  $demands
     * @return array<int, array<string, mixed>>
     */
    private function demandRows(EloquentCollection $demands): array
    {
        return $demands->map(fn (Demanda $demand): array => $this->demandRow($demand))->all();
    }

    /** @return array<string, mixed> */
    private function demandRow(Demanda $demand): array
    {
        return [
            'id' => $demand->id,
            'protocol' => $demand->protocolo,
            'title' => $demand->titulo,
            'status' => $demand->status->value,
            'status_label' => $demand->status->label(),
            'priority' => $demand->prioridade->value,
            'priority_label' => $demand->prioridade->label(),
            'origin' => $demand->origem->value,
            'origin_label' => $demand->origem->label(),
            'citizen' => $demand->cidadao?->nome,
            'category' => $demand->categoria?->nome,
            'neighborhood' => $demand->bairro?->nome,
            'responsible' => $demand->responsavel?->name,
            'opened_at' => $demand->aberta_em->toIso8601String(),
            'deadline' => $demand->prazo?->toIso8601String(),
            'completed_at' => $demand->concluida_em?->toIso8601String(),
            'overdue' => $demand->isOverdue(),
        ];
    }
}

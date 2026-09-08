<?php

namespace App\Services\Admin;

use App\Enums\DemandStatus;
use App\Enums\GabineteStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\Gabinete;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlatformMetricsService
{
    /** @return array<string, mixed> */
    public function build(): array
    {
        $offices = Gabinete::withoutGlobalScopes();
        $users = User::query()->where('role', '!=', UserRole::Root);
        $demands = Demanda::withoutGlobalScopes();

        return [
            'summary' => [
                'offices' => (clone $offices)->count(),
                'active_offices' => (clone $offices)->where('status', GabineteStatus::Active)->count(),
                'suspended_offices' => (clone $offices)->where('status', GabineteStatus::Suspended)->count(),
                'active_users' => (clone $users)->where('is_active', true)->count(),
                'demands' => (clone $demands)->count(),
                'demands_last_30_days' => (clone $demands)->where('created_at', '>=', now()->subDays(30))->count(),
                'appointments_next_30_days' => Appointment::withoutGlobalScopes()
                    ->whereBetween('inicio_em', [now(), now()->addDays(30)])
                    ->count(),
                'exports_last_30_days' => ReportExport::withoutGlobalScopes()
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count(),
            ],
            'usage' => $this->usage(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function usage(): array
    {
        $userCounts = User::query()
            ->whereNotNull('gabinete_id')
            ->where('role', '!=', UserRole::Root)
            ->selectRaw('gabinete_id, count(*) as total')
            ->groupBy('gabinete_id');
        $citizenCounts = Cidadao::withoutGlobalScopes()
            ->selectRaw('gabinete_id, count(*) as total')
            ->groupBy('gabinete_id');
        $demandCounts = Demanda::withoutGlobalScopes()
            ->selectRaw('gabinete_id, count(*) as total')
            ->groupBy('gabinete_id');
        $openCounts = Demanda::withoutGlobalScopes()
            ->whereIn('status', DemandStatus::openValues())
            ->selectRaw('gabinete_id, count(*) as total')
            ->groupBy('gabinete_id');

        return Gabinete::withoutGlobalScopes()
            ->leftJoinSub($userCounts, 'user_counts', 'user_counts.gabinete_id', '=', 'gabinetes.id')
            ->leftJoinSub($citizenCounts, 'citizen_counts', 'citizen_counts.gabinete_id', '=', 'gabinetes.id')
            ->leftJoinSub($demandCounts, 'demand_counts', 'demand_counts.gabinete_id', '=', 'gabinetes.id')
            ->leftJoinSub($openCounts, 'open_counts', 'open_counts.gabinete_id', '=', 'gabinetes.id')
            ->select([
                'gabinetes.id',
                'gabinetes.nome',
                'gabinetes.status',
                'gabinetes.municipio',
                'gabinetes.estado',
                DB::raw('coalesce(user_counts.total, 0) as users_count'),
                DB::raw('coalesce(citizen_counts.total, 0) as citizens_count'),
                DB::raw('coalesce(demand_counts.total, 0) as demands_count'),
                DB::raw('coalesce(open_counts.total, 0) as open_demands_count'),
            ])
            ->orderByDesc('demands_count')
            ->limit(10)
            ->get()
            ->map(fn (Gabinete $office): array => [
                'id' => $office->id,
                'name' => $office->nome,
                'status' => $office->status->value,
                'status_label' => $office->status->label(),
                'city' => $office->municipio,
                'state' => $office->estado,
                'users' => (int) $office->getAttribute('users_count'),
                'citizens' => (int) $office->getAttribute('citizens_count'),
                'demands' => (int) $office->getAttribute('demands_count'),
                'open_demands' => (int) $office->getAttribute('open_demands_count'),
            ])
            ->all();
    }
}

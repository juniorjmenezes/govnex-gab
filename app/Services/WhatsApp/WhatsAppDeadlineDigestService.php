<?php

namespace App\Services\WhatsApp;

use App\Enums\DemandStatus;
use App\Enums\GabineteModule;
use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppPurpose;
use App\Models\Demanda;
use App\Models\Gabinete;
use App\Models\User;
use App\Models\WhatsAppConfiguration;
use App\Services\Modules\GabineteModuleManager;
use Carbon\CarbonImmutable;

final class WhatsAppDeadlineDigestService
{
    public function __construct(
        private readonly WhatsAppEventNotificationService $notifications,
        private readonly GabineteModuleManager $modules,
    ) {}

    public function dispatchDue(): int
    {
        $sent = 0;
        $configurations = WhatsAppConfiguration::withoutGlobalScopes()
            ->where('modo', '!=', WhatsAppMode::Off->value)
            ->get();
        foreach ($configurations as $configuration) {
            if (! in_array(WhatsAppPurpose::DemandDeadlineDigest->value, (array) $configuration->finalidades_habilitadas, true)) {
                continue;
            }
            $office = Gabinete::withoutGlobalScopes()->find($configuration->gabinete_id);
            if (! $office) {
                continue;
            }
            if (! $this->modules->isActive($office, GabineteModule::WhatsApp)
                || ! $this->modules->isActive($office, GabineteModule::Demands)) {
                continue;
            }
            $now = CarbonImmutable::now($office->timezone ?: 'America/Fortaleza');
            if (substr((string) $configuration->resumo_diario_em, 0, 5) !== $now->format('H:i')) {
                continue;
            }
            $users = User::query()
                ->where('gabinete_id', $office->id)
                ->where('is_active', true)
                ->whereHas('whatsappContact')
                ->get();
            foreach ($users as $user) {
                [$overdue, $soon, $referrals] = $this->counts($user, $now->utc());
                if (($overdue + $soon + $referrals) === 0) {
                    continue;
                }
                $this->notifications->enqueueForUser(
                    $user,
                    WhatsAppPurpose::DemandDeadlineDigest,
                    "deadline-digest:{$office->id}:{$user->id}:{$now->toDateString()}",
                    [$user->name, (string) $overdue, (string) $soon, (string) $referrals],
                );
                $sent++;
            }
        }

        return $sent;
    }

    /** @return array{int,int,int} */
    public function counts(User $user, CarbonImmutable $now): array
    {
        $base = Demanda::withoutGlobalScopes()
            ->where('gabinete_id', $user->gabinete_id)
            ->where('responsavel_id', $user->id)
            ->whereIn('status', DemandStatus::openValues())
            ->whereNotNull('prazo');
        $overdue = (clone $base)->where('prazo', '<', $now)->count();
        $soon = (clone $base)->whereBetween('prazo', [$now, $now->addDay()])->count();
        $nextActions = Demanda::withoutGlobalScopes()
            ->where('gabinete_id', $user->gabinete_id)
            ->whereNull('proxima_acao_concluida_em')
            ->whereNotNull('proxima_acao_data')
            ->where(fn ($query) => $query->where('proxima_acao_responsavel_id', $user->id)
                ->orWhere(fn ($query) => $query->whereNull('proxima_acao_responsavel_id')->where('responsavel_id', $user->id)))
            ->whereBetween('proxima_acao_data', [$now->toDateString(), $now->addDay()->toDateString()])
            ->count();

        return [$overdue, $soon, $nextActions];
    }
}

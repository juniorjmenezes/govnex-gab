<?php

namespace App\Services\Politics;

use App\Enums\OfficeModule;
use App\Enums\OfficePosition;
use App\Models\ElectoralPoll;
use App\Models\FavoriteCandidate;
use App\Models\OfficeMember;
use App\Models\User;
use App\Notifications\PoliticalPollPublishedNotification;
use App\Services\Modules\OfficeModuleManager;
use App\Services\WhatsApp\WhatsAppEventNotificationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

class PoliticalPollNotificationService
{
    public function __construct(
        private readonly WhatsAppEventNotificationService $whatsApp,
        private readonly OfficeModuleManager $modules,
    ) {}

    public function notifyFavorites(ElectoralPoll $poll): int
    {
        $candidateNames = $poll->resultados()
            ->whereNotNull('candidato_politico_id')
            ->with('candidato:id,nome_urna')
            ->get(['candidato_politico_id', 'candidato_nome'])
            ->unique('candidato_politico_id')
            ->mapWithKeys(fn ($result): array => [
                $result->candidato_politico_id => $result->candidato?->nome_urna
                    ?: $result->candidato_nome,
            ]);

        if ($candidateNames->isEmpty()) {
            return 0;
        }

        $favoritesByOffice = FavoriteCandidate::withoutGlobalScopes()
            ->whereIn('candidato_politico_id', $candidateNames->keys())
            ->get(['gabinete_id', 'candidato_politico_id'])
            ->groupBy('gabinete_id');

        if ($favoritesByOffice->isEmpty()) {
            return 0;
        }

        // Quem lidera o gabinete pelo cargo local (vereador ou chefe de gabinete).
        $recipients = OfficeMember::query()
            ->whereIn('gabinete_id', $favoritesByOffice->keys())
            ->where('ativo', true)
            ->whereIn('cargo', [OfficePosition::Councilor->value, OfficePosition::ChiefOfStaff->value])
            ->whereHas('usuario', fn ($query) => $query->where('is_active', true))
            ->with('usuario')
            ->get()
            ->groupBy('gabinete_id')
            ->map(fn (Collection $memberships): Collection => $memberships->map(fn (OfficeMember $membership): User => $membership->usuario));
        $sent = 0;

        foreach ($favoritesByOffice as $officeId => $favorites) {
            if (! $this->modules->isActive((int) $officeId, OfficeModule::Politics)) {
                continue;
            }
            $names = $favorites
                ->map(fn (FavoriteCandidate $favorite): ?string => $candidateNames->get($favorite->candidato_politico_id))
                ->filter()
                ->unique()
                ->values()
                ->all();

            foreach ($recipients->get($officeId, Collection::make()) as $recipient) {
                if ($this->alreadySent($recipient, $poll)) {
                    continue;
                }

                $recipient->notify(new PoliticalPollPublishedNotification($poll, $names));
                $this->whatsApp->politicalPollPublished($poll, $recipient);
                $sent++;
            }
        }

        return $sent;
    }

    private function alreadySent(User $recipient, ElectoralPoll $poll): bool
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $recipient->id)
            ->where('data', 'like', '%"key":"political-poll:'.$poll->external_id.'"%')
            ->exists();
    }
}

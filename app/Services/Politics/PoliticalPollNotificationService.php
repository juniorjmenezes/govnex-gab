<?php

namespace App\Services\Politics;

use App\Enums\GabineteModule;
use App\Enums\UserRole;
use App\Models\CandidatoFavorito;
use App\Models\PesquisaEleitoral;
use App\Models\User;
use App\Notifications\PoliticalPollPublishedNotification;
use App\Services\Modules\GabineteModuleManager;
use App\Services\WhatsApp\WhatsAppEventNotificationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

class PoliticalPollNotificationService
{
    public function __construct(
        private readonly WhatsAppEventNotificationService $whatsApp,
        private readonly GabineteModuleManager $modules,
    ) {}

    public function notifyFavorites(PesquisaEleitoral $poll): int
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

        $favoritesByOffice = CandidatoFavorito::withoutGlobalScopes()
            ->whereIn('candidato_politico_id', $candidateNames->keys())
            ->get(['gabinete_id', 'candidato_politico_id'])
            ->groupBy('gabinete_id');

        if ($favoritesByOffice->isEmpty()) {
            return 0;
        }

        $recipients = User::query()
            ->whereIn('gabinete_id', $favoritesByOffice->keys())
            ->where('role', UserRole::Administrator)
            ->where('is_active', true)
            ->get()
            ->groupBy('gabinete_id');
        $sent = 0;

        foreach ($favoritesByOffice as $officeId => $favorites) {
            if (! $this->modules->isActive((int) $officeId, GabineteModule::Politics)) {
                continue;
            }
            $names = $favorites
                ->map(fn (CandidatoFavorito $favorite): ?string => $candidateNames->get($favorite->candidato_politico_id))
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

    private function alreadySent(User $recipient, PesquisaEleitoral $poll): bool
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $recipient->id)
            ->where('data', 'like', '%"key":"political-poll:'.$poll->external_id.'"%')
            ->exists();
    }
}

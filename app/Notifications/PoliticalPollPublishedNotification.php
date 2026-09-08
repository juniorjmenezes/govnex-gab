<?php

namespace App\Notifications;

use App\Models\PesquisaEleitoral;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PoliticalPollPublishedNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<int, string>  $favoriteNames
     */
    public function __construct(
        private readonly PesquisaEleitoral $poll,
        private readonly array $favoriteNames,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $institute = $this->poll->instituto ?: 'Um instituto';
        $office = match ($this->poll->cargo) {
            'presidente' => 'Presidente',
            'governador' => 'Governador',
            'senador' => 'Senado',
            'prefeito' => 'Prefeito',
            default => ucfirst($this->poll->cargo),
        };
        $favorites = $this->formatNames($this->favoriteNames);

        return [
            'key' => "political-poll:{$this->poll->external_id}",
            'kind' => 'political_poll_published',
            'title' => 'Nova pesquisa com candidato favorito',
            'message' => "{$institute} publicou uma pesquisa para {$office} envolvendo {$favorites}.",
            'url' => route('politics.index', ['eleicao_id' => $this->poll->eleicao_id], false).'#pesquisas',
            'poll_id' => $this->poll->id,
            'election_id' => $this->poll->eleicao_id,
        ];
    }

    /** @param array<int, string> $names */
    private function formatNames(array $names): string
    {
        if (count($names) < 2) {
            return $names[0] ?? 'um candidato favorito';
        }

        $last = array_pop($names);

        return implode(', ', $names).' e '.$last;
    }
}

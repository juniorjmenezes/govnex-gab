<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DemandActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $key,
        private readonly string $title,
        private readonly string $message,
        private readonly int $demandId,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'key' => $this->key,
            'kind' => 'demand_activity',
            'title' => $this->title,
            'message' => $this->message,
            'url' => route('demands.show', $this->demandId, false),
            'demand_id' => $this->demandId,
        ];
    }
}

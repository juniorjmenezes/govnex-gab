<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AppointmentReminderNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Appointment $appointment) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'appointment_reminder',
            'title' => 'Compromisso próximo',
            'message' => $this->appointment->titulo.' começa em '.$this->appointment->inicio_em->format('d/m/Y H:i').'.',
            'url' => route('appointments.index', [
                'view' => 'dia',
                'date' => $this->appointment->inicio_em->toDateString(),
            ], false),
            'appointment_id' => $this->appointment->id,
        ];
    }
}

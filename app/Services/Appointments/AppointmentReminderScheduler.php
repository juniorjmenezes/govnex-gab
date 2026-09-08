<?php

namespace App\Services\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\ReminderStatus;
use App\Models\Appointment;
use Illuminate\Support\Carbon;

class AppointmentReminderScheduler
{
    /** @param array<int, array<string, mixed>> $reminders */
    public function replace(Appointment $appointment, array $reminders): void
    {
        $appointment->lembretes()
            ->whereIn('status', [ReminderStatus::Pending, ReminderStatus::Processing])
            ->update([
                'ativo' => false,
                'status' => ReminderStatus::Cancelled,
                'processado_em' => now(),
            ]);

        if ($appointment->status === AppointmentStatus::Cancelled) {
            return;
        }

        foreach ($reminders as $reminder) {
            $minutes = (int) $reminder['antecedencia_minutos'];
            $appointment->lembretes()->create([
                'canal' => $reminder['canal'],
                'antecedencia_minutos' => $minutes,
                'destinatarios' => array_values($reminder['destinatarios']),
                'ativo' => (bool) ($reminder['ativo'] ?? true),
                'agendado_para' => Carbon::parse($appointment->inicio_em)->subMinutes($minutes),
                'status' => ReminderStatus::Pending,
            ]);
        }
    }

    public function cancel(Appointment $appointment): void
    {
        $appointment->lembretes()
            ->whereIn('status', [ReminderStatus::Pending, ReminderStatus::Processing])
            ->update([
                'ativo' => false,
                'status' => ReminderStatus::Cancelled,
                'processado_em' => now(),
            ]);
    }
}

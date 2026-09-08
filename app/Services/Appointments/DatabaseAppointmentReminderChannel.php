<?php

namespace App\Services\Appointments;

use App\Contracts\Appointments\AppointmentReminderChannel;
use App\Enums\ReminderStatus;
use App\Models\AppointmentReminder;
use App\Models\User;
use App\Notifications\AppointmentReminderNotification;

class DatabaseAppointmentReminderChannel implements AppointmentReminderChannel
{
    public function send(AppointmentReminder $reminder, string $recipient): ReminderStatus
    {
        $user = User::query()
            ->whereKey((int) $recipient)
            ->where('gabinete_id', $reminder->gabinete_id)
            ->where('is_active', true)
            ->firstOrFail();

        $user->notify(new AppointmentReminderNotification($reminder->compromisso));

        return ReminderStatus::Sent;
    }
}

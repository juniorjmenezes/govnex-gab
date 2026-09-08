<?php

namespace App\Contracts\Appointments;

use App\Enums\ReminderStatus;
use App\Models\AppointmentReminder;

interface AppointmentReminderChannel
{
    public function send(AppointmentReminder $reminder, string $recipient): ReminderStatus;
}

<?php

namespace App\Services\Appointments;

use App\Contracts\Appointments\AppointmentReminderChannel;
use App\Enums\ReminderChannel;

class AppointmentReminderChannelManager
{
    public function __construct(
        private readonly DatabaseAppointmentReminderChannel $database,
        private readonly FakeWhatsAppAppointmentReminderChannel $fakeWhatsApp,
        private readonly WhatsAppAppointmentReminderChannel $whatsApp,
    ) {}

    public function for(ReminderChannel $channel): AppointmentReminderChannel
    {
        return match ($channel) {
            ReminderChannel::Internal => $this->database,
            ReminderChannel::FakeWhatsApp => $this->fakeWhatsApp,
            ReminderChannel::WhatsApp => $this->whatsApp,
        };
    }
}

<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->gabinete_id !== null;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->gabinete_id === $appointment->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $user->gabinete_id !== null;
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $this->view($user, $appointment);
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $this->view($user, $appointment);
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $this->view($user, $appointment)
            && in_array($user->role, [UserRole::Councilor, UserRole::ChiefOfStaff], true);
    }
}

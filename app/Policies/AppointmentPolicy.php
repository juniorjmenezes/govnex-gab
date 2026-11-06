<?php

namespace App\Policies;

use App\Authorization\OfficeAccess;
use App\Authorization\OfficeCapability;
use App\Models\Appointment;
use App\Models\User;
use App\Tenancy\OfficeContext;

class AppointmentPolicy
{
    public function __construct(
        private readonly OfficeContext $context,
        private readonly OfficeAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $this->context->officeIdFor($user) === $appointment->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null && $this->access->can($user, OfficeCapability::WriteRecords);
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $this->view($user, $appointment) && $this->access->can($user, OfficeCapability::WriteRecords);
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $this->update($user, $appointment);
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $this->view($user, $appointment)
            && $this->access->can($user, OfficeCapability::DeleteRecords);
    }
}

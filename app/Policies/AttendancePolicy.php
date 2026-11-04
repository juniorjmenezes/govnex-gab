<?php

namespace App\Policies;

use App\Authorization\OfficeAccess;
use App\Authorization\OfficeCapability;
use App\Models\Attendance;
use App\Models\User;
use App\Tenancy\OfficeContext;

class AttendancePolicy
{
    public function __construct(
        private readonly OfficeContext $context,
        private readonly OfficeAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null;
    }

    public function view(User $user, Attendance $atendimento): bool
    {
        return $this->context->officeIdFor($user) === $atendimento->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null && $this->access->can($user, OfficeCapability::WriteRecords);
    }

    public function update(User $user, Attendance $atendimento): bool
    {
        return $this->view($user, $atendimento) && $this->access->can($user, OfficeCapability::WriteRecords);
    }

    public function delete(User $user, Attendance $atendimento): bool
    {
        return $this->view($user, $atendimento)
            && $this->access->can($user, OfficeCapability::DeleteRecords);
    }
}

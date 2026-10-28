<?php

namespace App\Policies;

use App\Models\Demand;
use App\Models\User;
use App\Tenancy\OfficeContext;

class DemandPolicy
{
    public function __construct(private readonly OfficeContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null;
    }

    public function view(User $user, Demand $demanda): bool
    {
        return $this->context->officeIdFor($user) === $demanda->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null && $this->context->roleFor($user)->canWrite();
    }

    public function update(User $user, Demand $demanda): bool
    {
        return $this->view($user, $demanda) && $this->context->roleFor($user)->canWrite();
    }

    public function delete(User $user, Demand $demanda): bool
    {
        return $this->view($user, $demanda)
            && $this->context->roleFor($user)->isAdministrator();
    }
}

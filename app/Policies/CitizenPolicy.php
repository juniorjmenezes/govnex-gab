<?php

namespace App\Policies;

use App\Models\Citizen;
use App\Models\User;
use App\Tenancy\OfficeContext;

class CitizenPolicy
{
    public function __construct(private readonly OfficeContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null;
    }

    public function view(User $user, Citizen $cidadao): bool
    {
        return $this->context->officeIdFor($user) === $cidadao->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null && $this->context->roleFor($user)->canWrite();
    }

    public function update(User $user, Citizen $cidadao): bool
    {
        return $this->view($user, $cidadao) && $this->context->roleFor($user)->canWrite();
    }

    public function delete(User $user, Citizen $cidadao): bool
    {
        return $this->view($user, $cidadao)
            && $this->context->roleFor($user)->isAdministrator();
    }
}

<?php

namespace App\Policies;

use App\Models\Neighborhood;
use App\Models\User;
use App\Tenancy\OfficeContext;

class NeighborhoodPolicy
{
    public function __construct(private readonly OfficeContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null;
    }

    public function view(User $user, Neighborhood $bairro): bool
    {
        return $this->context->officeIdFor($user) === $bairro->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $this->context->roleFor($user)->isAdministrator();
    }

    public function update(User $user, Neighborhood $bairro): bool
    {
        return $this->view($user, $bairro) && $this->create($user);
    }

    public function delete(User $user, Neighborhood $bairro): bool
    {
        return $this->update($user, $bairro);
    }
}

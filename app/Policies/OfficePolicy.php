<?php

namespace App\Policies;

use App\Models\Office;
use App\Models\User;
use App\Tenancy\OfficeContext;

class OfficePolicy
{
    public function __construct(private readonly OfficeContext $context) {}

    public function viewAny(User $user): bool
    {
        return $user->isRoot()
            || $this->context->roleFor($user)->isAdministrator();
    }

    public function view(User $user, Office $gabinete): bool
    {
        return $user->isRoot() || $this->context->officeIdFor($user) === $gabinete->id;
    }

    public function create(User $user): bool
    {
        return $user->isRoot();
    }

    public function update(User $user, Office $gabinete): bool
    {
        return $user->isRoot()
            || ($this->context->roleFor($user)->isAdministrator() && $this->context->officeIdFor($user) === $gabinete->id);
    }

    public function suspend(User $user, Office $gabinete): bool
    {
        return $user->isRoot();
    }

    public function restore(User $user, Office $gabinete): bool
    {
        return $user->isRoot();
    }

    public function forceDelete(User $user, Office $gabinete): bool
    {
        return false;
    }
}

<?php

namespace App\Policies;

use App\Authorization\OfficeAccess;
use App\Authorization\OfficeCapability;
use App\Models\Office;
use App\Models\User;
use App\Tenancy\OfficeContext;

class OfficePolicy
{
    public function __construct(
        private readonly OfficeContext $context,
        private readonly OfficeAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->isRoot()
            || $this->access->can($user, OfficeCapability::ConfigureOffice);
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
            || ($this->access->can($user, OfficeCapability::ConfigureOffice) && $this->context->officeIdFor($user) === $gabinete->id);
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

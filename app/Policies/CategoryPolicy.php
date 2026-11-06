<?php

namespace App\Policies;

use App\Authorization\OfficeAccess;
use App\Authorization\OfficeCapability;
use App\Models\Category;
use App\Models\User;
use App\Tenancy\OfficeContext;

class CategoryPolicy
{
    public function __construct(
        private readonly OfficeContext $context,
        private readonly OfficeAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null;
    }

    public function view(User $user, Category $categoria): bool
    {
        return $this->context->officeIdFor($user) === $categoria->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $this->access->can($user, OfficeCapability::ConfigureOffice);
    }

    public function update(User $user, Category $categoria): bool
    {
        return $this->view($user, $categoria) && $this->create($user);
    }

    public function delete(User $user, Category $categoria): bool
    {
        return $this->update($user, $categoria);
    }
}

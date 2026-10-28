<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Tenancy\OfficeContext;

class CategoryPolicy
{
    public function __construct(private readonly OfficeContext $context) {}

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
        return $this->context->roleFor($user)->isAdministrator();
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

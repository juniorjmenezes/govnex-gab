<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Tenancy\OfficeContext;

class EventPolicy
{
    public function __construct(private readonly OfficeContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null;
    }

    public function view(User $user, Event $evento): bool
    {
        return $this->context->officeIdFor($user) === $evento->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null && $this->context->roleFor($user)->canWrite();
    }

    public function update(User $user, Event $evento): bool
    {
        return $this->view($user, $evento) && $this->context->roleFor($user)->canWrite();
    }

    public function delete(User $user, Event $evento): bool
    {
        return $this->view($user, $evento)
            && $this->context->roleFor($user)->isAdministrator();
    }
}

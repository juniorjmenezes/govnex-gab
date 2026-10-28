<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Entidades\EntityMembershipService;

class UserEntityMembershipObserver
{
    public function saved(User $user): void
    {
        app(EntityMembershipService::class)->syncLegacyUser($user);
    }

    public function deleted(User $user): void
    {
        if ($user->isForceDeleting()) {
            return;
        }

        $user->forceFill(['is_active' => false]);
        app(EntityMembershipService::class)->syncLegacyUser($user);
    }
}

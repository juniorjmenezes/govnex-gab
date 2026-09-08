<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Entidades\EntidadeMembershipService;

class UserEntidadeMembershipObserver
{
    public function saved(User $user): void
    {
        app(EntidadeMembershipService::class)->syncLegacyUser($user);
    }

    public function deleted(User $user): void
    {
        if ($user->isForceDeleting()) {
            return;
        }

        $user->forceFill(['is_active' => false]);
        app(EntidadeMembershipService::class)->syncLegacyUser($user);
    }
}

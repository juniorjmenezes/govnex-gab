<?php

namespace App\Policies;

use App\Models\DemandaAnexo;
use App\Models\User;

class DemandaAnexoPolicy
{
    public function view(User $user, DemandaAnexo $attachment): bool
    {
        return $user->gabinete_id === $attachment->gabinete_id;
    }

    public function delete(User $user, DemandaAnexo $attachment): bool
    {
        return $this->view($user, $attachment);
    }
}

<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ReportExport;
use App\Models\User;

class ReportExportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->gabinete_id !== null
            && in_array($user->role, [UserRole::Councilor, UserRole::ChiefOfStaff], true);
    }

    public function view(User $user, ReportExport $export): bool
    {
        return $this->viewAny($user) && $user->gabinete_id === $export->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function download(User $user, ReportExport $export): bool
    {
        return $this->view($user, $export);
    }
}

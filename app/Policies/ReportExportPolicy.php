<?php

namespace App\Policies;

use App\Models\ReportExport;
use App\Models\User;
use App\Tenancy\OfficeContext;

class ReportExportPolicy
{
    public function __construct(private readonly OfficeContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null
            && $this->context->roleFor($user)->isAdministrator();
    }

    public function view(User $user, ReportExport $export): bool
    {
        return $this->viewAny($user) && $this->context->officeIdFor($user) === $export->gabinete_id;
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

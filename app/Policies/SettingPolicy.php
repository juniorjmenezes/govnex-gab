<?php

namespace App\Policies;

use App\Authorization\OfficeAccess;
use App\Authorization\OfficeCapability;
use App\Models\Setting;
use App\Models\User;
use App\Tenancy\OfficeContext;

class SettingPolicy
{
    public function __construct(
        private readonly OfficeContext $context,
        private readonly OfficeAccess $access,
    ) {}

    public function view(User $user, Setting $configuracao): bool
    {
        return $this->context->officeIdFor($user) === $configuracao->gabinete_id;
    }

    public function update(User $user, Setting $configuracao): bool
    {
        return $this->view($user, $configuracao) && $this->access->can($user, OfficeCapability::ConfigureOffice);
    }
}

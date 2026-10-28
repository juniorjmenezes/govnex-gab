<?php

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;
use App\Tenancy\OfficeContext;

class SettingPolicy
{
    public function __construct(private readonly OfficeContext $context) {}

    public function view(User $user, Setting $configuracao): bool
    {
        return $this->context->officeIdFor($user) === $configuracao->gabinete_id;
    }

    public function update(User $user, Setting $configuracao): bool
    {
        return $this->view($user, $configuracao) && $this->context->roleFor($user)->isAdministrator();
    }
}

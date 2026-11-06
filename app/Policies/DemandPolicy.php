<?php

namespace App\Policies;

use App\Authorization\OfficeAccess;
use App\Authorization\OfficeCapability;
use App\Models\Demand;
use App\Models\User;
use App\Tenancy\OfficeContext;

class DemandPolicy
{
    public function __construct(
        private readonly OfficeContext $context,
        private readonly OfficeAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null;
    }

    public function view(User $user, Demand $demanda): bool
    {
        return $this->context->officeIdFor($user) === $demanda->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null && $this->access->can($user, OfficeCapability::WriteRecords);
    }

    public function update(User $user, Demand $demanda): bool
    {
        return $this->view($user, $demanda) && $this->access->can($user, OfficeCapability::WriteRecords);
    }

    /**
     * Redistribuir: trocar (ou retirar) o responsável de uma demanda que já
     * tem um. Atribuir responsável a demanda sem responsável é só `update`.
     */
    public function reassign(User $user, Demand $demanda): bool
    {
        return $this->update($user, $demanda)
            && $this->access->can($user, OfficeCapability::ReassignDemands);
    }

    public function delete(User $user, Demand $demanda): bool
    {
        return $this->view($user, $demanda)
            && $this->access->can($user, OfficeCapability::DeleteRecords);
    }
}

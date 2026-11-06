<?php

namespace App\Policies;

use App\Authorization\OfficeAccess;
use App\Authorization\OfficeCapability;
use App\Models\DemandAttachment;
use App\Models\User;
use App\Tenancy\OfficeContext;

class DemandAttachmentPolicy
{
    public function __construct(
        private readonly OfficeContext $context,
        private readonly OfficeAccess $access,
    ) {}

    public function view(User $user, DemandAttachment $attachment): bool
    {
        return $this->context->officeIdFor($user) === $attachment->gabinete_id;
    }

    public function delete(User $user, DemandAttachment $attachment): bool
    {
        return $this->view($user, $attachment) && $this->access->can($user, OfficeCapability::WriteRecords);
    }
}

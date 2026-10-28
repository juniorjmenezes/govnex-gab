<?php

namespace App\Policies;

use App\Models\DemandAttachment;
use App\Models\User;
use App\Tenancy\OfficeContext;

class DemandAttachmentPolicy
{
    public function __construct(private readonly OfficeContext $context) {}

    public function view(User $user, DemandAttachment $attachment): bool
    {
        return $this->context->officeIdFor($user) === $attachment->gabinete_id;
    }

    public function delete(User $user, DemandAttachment $attachment): bool
    {
        return $this->view($user, $attachment) && $this->context->roleFor($user)->canWrite();
    }
}

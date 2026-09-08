<?php

namespace App\Tenancy;

use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use LogicException;

class GabineteContext
{
    private ?Gabinete $explicitUnit = null;

    public function setUnit(Gabinete $gabinete): void
    {
        $this->explicitUnit = $gabinete;
    }

    public function clear(): void
    {
        $this->explicitUnit = null;
    }

    public function gabinete(): ?Gabinete
    {
        return $this->explicitUnit;
    }

    public function hasExplicitUnit(): bool
    {
        return $this->explicitUnit !== null;
    }

    public function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function id(): ?int
    {
        return $this->explicitUnit !== null
            ? $this->explicitUnit->id
            : $this->user()?->gabinete_id;
    }

    public function hasAuthenticatedUser(): bool
    {
        return $this->user() !== null;
    }

    public function bypassesTenantScope(): bool
    {
        return ! $this->hasExplicitUnit() && ($this->user()?->isRoot() ?? false);
    }

    public function requireId(): int
    {
        return $this->id() ?? throw new LogicException('Não há gabinete ativo no contexto atual.');
    }
}

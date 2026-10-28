<?php

namespace App\Tenancy;

use App\Models\Entity;
use LogicException;

class EntityContext
{
    private ?Entity $entidade = null;

    public function set(Entity $entidade): void
    {
        $this->entidade = $entidade;
    }

    public function clear(): void
    {
        $this->entidade = null;
    }

    public function entidade(): ?Entity
    {
        return $this->entidade;
    }

    public function id(): ?int
    {
        return $this->entidade?->id;
    }

    public function requireEntidade(): Entity
    {
        return $this->entidade
            ?? throw new LogicException('Não há entidade ativa no contexto atual.');
    }
}

<?php

namespace App\Tenancy;

use App\Models\Entidade;
use LogicException;

class EntidadeContext
{
    private ?Entidade $entidade = null;

    public function set(Entidade $entidade): void
    {
        $this->entidade = $entidade;
    }

    public function clear(): void
    {
        $this->entidade = null;
    }

    public function entidade(): ?Entidade
    {
        return $this->entidade;
    }

    public function id(): ?int
    {
        return $this->entidade?->id;
    }

    public function requireEntidade(): Entidade
    {
        return $this->entidade
            ?? throw new LogicException('Não há entidade ativa no contexto atual.');
    }
}

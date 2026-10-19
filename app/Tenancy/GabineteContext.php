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

    /**
     * Executa `$callback` com `$gabinete` como contexto explícito e devolve o
     * contexto anterior ao final, mesmo em caso de exceção.
     *
     * Para fluxos que agem deliberadamente em nome de outro gabinete dentro
     * de uma requisição (ex.: a Secretaria legislativa convocando gabinetes
     * de vereador): o escopo global e o preenchimento de `gabinete_id` na
     * criação passam a valer para o gabinete de destino, e não para o da
     * requisição. Quem chama responde por ter autorizado a travessia.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Gabinete $gabinete, callable $callback): mixed
    {
        $previous = $this->explicitUnit;
        $this->explicitUnit = $gabinete;

        try {
            return $callback();
        } finally {
            $this->explicitUnit = $previous;
        }
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

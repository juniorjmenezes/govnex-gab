<?php

namespace App\Tenancy;

use App\Enums\OfficePosition;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use LogicException;

class OfficeContext
{
    private ?Office $explicitUnit = null;

    /**
     * Gabinete resolvido pela URL da requisição. Diferente de `$explicitUnit`,
     * não muda dentro de `runAs()`: é onde a pessoa autenticada atua (e com
     * qual cargo), mesmo quando um fluxo grava temporariamente em outro
     * gabinete.
     */
    private ?Office $requestUnit = null;

    /** @var array<int, OfficePosition|null> */
    private array $requestPositions = [];

    public function setUnit(Office $gabinete): void
    {
        $this->explicitUnit = $gabinete;
        $this->requestUnit = $gabinete;
        $this->requestPositions = [];
    }

    public function clear(): void
    {
        $this->explicitUnit = null;
        $this->requestUnit = null;
        $this->requestPositions = [];
    }

    /**
     * Gabinete em que `$user` atua agora. Para a pessoa autenticada numa rota
     * com contexto, é o gabinete da URL; fora disso (filas, comandos, outra
     * pessoa que não a autenticada), é o gabinete gravado na conta.
     */
    public function officeIdFor(?User $user): ?int
    {
        return $this->actsInRequestUnit($user)
            ? $this->requestUnit?->id
            : $user?->gabinete_id;
    }

    public function officeFor(?User $user): ?Office
    {
        return $this->actsInRequestUnit($user)
            ? $this->requestUnit
            : $user?->gabinete;
    }

    /**
     * Instância nova do gabinete em que `$user` atua, para quem vai alterá-lo
     * ou carregar relações: a de `officeFor()` é compartilhada pela
     * requisição (e recebe atributos só de exibição, como `logo_url`).
     */
    public function requireOfficeFor(?User $user): Office
    {
        $officeId = $this->officeIdFor($user);

        return $officeId !== null
            ? Office::query()->findOrFail($officeId)
            : throw (new ModelNotFoundException)->setModel(Office::class);
    }

    /**
     * Cargo local de `$user` no gabinete em que atua (`gabinete_membros.cargo`
     * do vínculo ativo), ou `null` sem cargo. Root não tem vínculo, logo nunca
     * tem cargo.
     */
    public function positionFor(User $user): ?OfficePosition
    {
        if ($user->isRoot()) {
            return null;
        }

        if (! $this->actsInRequestUnit($user) || $this->requestUnit === null) {
            $officeId = $this->officeIdFor($user);

            return $officeId !== null ? $user->gabinetePosition($officeId) : null;
        }

        if (! array_key_exists($user->id, $this->requestPositions)) {
            $this->requestPositions[$user->id] = $user->gabinetePosition($this->requestUnit->id);
        }

        return $this->requestPositions[$user->id];
    }

    private function actsInRequestUnit(?User $user): bool
    {
        return $this->requestUnit !== null && $user !== null && $user->is($this->user());
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
    public function runAs(Office $gabinete, callable $callback): mixed
    {
        $previous = $this->explicitUnit;
        $this->explicitUnit = $gabinete;

        try {
            return $callback();
        } finally {
            $this->explicitUnit = $previous;
        }
    }

    public function gabinete(): ?Office
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

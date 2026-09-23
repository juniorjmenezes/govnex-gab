<?php

namespace App\Services\Hub;

use App\Enums\AccessRole;
use App\Enums\UserRole;
use App\Models\Entidade;
use Illuminate\Support\Facades\Log;

/**
 * Tradução do `papel` que vem do Hub para o vocabulário do GAB.
 *
 * O Hub trata `Vinculo.papel` como texto opaco; o ecossistema fechou o
 * vocabulário (decisão #5 de `docs/INTEGRACAO_GOVNEX_HUB.md`) em
 * `administrador`, `operador` e `auditor` — o mesmo de `AccessRole`. Nada mais
 * é aceito: grafias transitórias (`LIDER`, `vereador`, `chefe_gabinete`…) não
 * são mais emitidas pelo Hub.
 *
 * Papel desconhecido vira **auditor** — menor privilégio — e deixa rastro em
 * log: um erro de digitação no Hub não pode conceder escrita. `root` não é
 * vínculo (é conta local, decisão #6) e o vínculo que o traga é ignorado.
 */
class HubPapelMapper
{
    public function isRoot(?string $papel): bool
    {
        return $this->normalizar($papel) === 'root';
    }

    public function accessRole(?string $papel): AccessRole
    {
        $role = match ($this->normalizar($papel)) {
            'administrador' => AccessRole::Administrator,
            'operador' => AccessRole::Operator,
            'auditor' => AccessRole::Auditor,
            default => null,
        };

        if ($role === null) {
            Log::warning('Papel do Hub desconhecido: aplicado como auditor (somente leitura).', [
                'papel' => $papel,
            ]);

            return AccessRole::Auditor;
        }

        return $role;
    }

    /**
     * Papel na entidade de um vínculo que aponta para uma unidade: o mesmo do
     * gabinete, exceto o administrador de gabinete em Câmara ou Prefeitura,
     * que é operador da entidade (ver `AccessRole::forEntidadeOfUnit`).
     */
    public function entidadeRoleDeUnidade(AccessRole $papel, ?Entidade $entidade): AccessRole
    {
        return $papel->forEntidadeOfUnit($entidade?->tipo);
    }

    /** O papel projetado em `users.role` a partir do vínculo principal. */
    public function userRole(AccessRole $papel): UserRole
    {
        return $papel->userRole();
    }

    private function normalizar(?string $papel): string
    {
        return strtolower(trim((string) $papel));
    }
}

<?php

namespace App\Policies;

use App\Enums\EntidadeModule;
use App\Enums\GabineteModule;
use App\Enums\GabineteType;
use App\Models\ComissaoLegislativa;
use App\Models\Gabinete;
use App\Models\User;
use App\Services\Modules\EntidadeModuleManager;
use App\Services\Modules\GabineteModuleManager;

/**
 * Gate assimétrico das comissões:
 *
 * - leitura: qualquer gabinete da mesma entidade, enquanto a Secretaria
 *   legislativa estiver ligada nela — gate de entidade, sem exigir módulo no
 *   gabinete (a rota de leitura não tem `module:`), como a biblioteca
 *   compartilhada em `ConhecimentoDocumentoPolicy::view()`;
 * - gestão: só a Secretaria legislativa da mesma entidade, com o módulo ativo
 *   no gabinete (que já embute o da entidade) e permissão de escrita. As
 *   rotas de gestão também passam por `module:SECRETARIA_LEGISLATIVA`; o
 *   módulo é repetido aqui para a tela de leitura saber se mostra a gestão.
 */
class ComissaoLegislativaPolicy
{
    public function __construct(
        private readonly GabineteModuleManager $modules,
        private readonly EntidadeModuleManager $entidadeModules,
    ) {}

    public function viewAny(User $user): bool
    {
        $gabinete = $this->gabinete($user);

        return $gabinete !== null
            && $this->entidadeModules->isActive($gabinete->entidade_id, EntidadeModule::LegislativeSecretariat);
    }

    public function view(User $user, ComissaoLegislativa $comissao): bool
    {
        return $this->viewAny($user)
            && $this->gabinete($user)?->entidade_id === $comissao->entidade_id;
    }

    public function create(User $user): bool
    {
        $gabinete = $this->gabinete($user);

        return $gabinete !== null
            && $gabinete->tipo_gabinete === GabineteType::LegislativeSecretariat
            && $user->role->canWrite()
            && $this->modules->isActive($gabinete, GabineteModule::LegislativeSecretariat);
    }

    /** Vale também para membros e documentos vinculados. */
    public function update(User $user, ComissaoLegislativa $comissao): bool
    {
        return $this->create($user)
            && $this->gabinete($user)?->entidade_id === $comissao->entidade_id;
    }

    public function delete(User $user, ComissaoLegislativa $comissao): bool
    {
        return $this->update($user, $comissao);
    }

    /**
     * O gabinete em contexto (`users.gabinete_id`), lido sem o escopo global
     * como em `CompromissoInstitucionalPolicy`.
     */
    private function gabinete(User $user): ?Gabinete
    {
        if ($user->gabinete_id === null) {
            return null;
        }

        $gabinete = $user->gabinete;

        return $gabinete?->id === $user->gabinete_id
            ? $gabinete
            : Gabinete::withoutGlobalScopes()->find($user->gabinete_id);
    }
}

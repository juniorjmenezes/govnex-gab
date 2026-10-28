<?php

namespace App\Policies;

use App\Enums\EntityModule;
use App\Enums\OfficeModule;
use App\Enums\OfficeType;
use App\Models\ComissaoLegislativa;
use App\Models\Office;
use App\Models\User;
use App\Services\Modules\EntityModuleManager;
use App\Services\Modules\OfficeModuleManager;
use App\Tenancy\OfficeContext;

/**
 * Gate assimétrico das comissões:
 *
 * - leitura: qualquer gabinete da mesma entidade, enquanto a Secretaria
 *   legislativa estiver ligada nela — gate de entidade, sem exigir módulo no
 *   gabinete (a rota de leitura não tem `module:`), como a biblioteca
 *   compartilhada em `KnowledgeDocumentPolicy::view()`;
 * - gestão: só a Secretaria legislativa da mesma entidade, com o módulo ativo
 *   no gabinete (que já embute o da entidade) e permissão de escrita. As
 *   rotas de gestão também passam por `module:SECRETARIA_LEGISLATIVA`; o
 *   módulo é repetido aqui para a tela de leitura saber se mostra a gestão.
 */
class ComissaoLegislativaPolicy
{
    public function __construct(
        private readonly OfficeModuleManager $modules,
        private readonly EntityModuleManager $entidadeModules,
        private readonly OfficeContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        $gabinete = $this->gabinete($user);

        return $gabinete !== null
            && $this->entidadeModules->isActive($gabinete->entidade_id, EntityModule::LegislativeSecretariat);
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
            && $gabinete->tipo_gabinete === OfficeType::LegislativeSecretariat
            && $this->context->roleFor($user)->canWrite()
            && $this->modules->isActive($gabinete, OfficeModule::LegislativeSecretariat);
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
     * O gabinete em que a pessoa atua (`OfficeContext::officeFor()`), lido
     * sem o escopo global como em `CompromissoInstitucionalPolicy`.
     */
    private function gabinete(User $user): ?Office
    {
        $officeId = $this->context->officeIdFor($user);

        if ($officeId === null) {
            return null;
        }

        $gabinete = $this->context->officeFor($user);

        return $gabinete?->id === $officeId
            ? $gabinete
            : Office::withoutGlobalScopes()->find($officeId);
    }
}

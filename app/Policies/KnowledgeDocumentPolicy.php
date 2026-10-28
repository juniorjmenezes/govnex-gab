<?php

namespace App\Policies;

use App\Enums\EntityModule;
use App\Enums\OfficeModule;
use App\Enums\OfficeType;
use App\Models\KnowledgeDocument;
use App\Models\Office;
use App\Models\User;
use App\Services\Modules\EntityModuleManager;
use App\Services\Modules\OfficeModuleManager;
use App\Tenancy\OfficeContext;

class KnowledgeDocumentPolicy
{
    public function __construct(
        private readonly OfficeModuleManager $modules,
        private readonly EntityModuleManager $entidadeModules,
        private readonly OfficeContext $context,
    ) {}

    /**
     * Biblioteca da plataforma: administradores e qualquer pessoa em contexto
     * de gabinete (o módulo é checado na rota). Compartilhado com a entidade:
     * qualquer gabinete daquela entidade, enquanto a Secretaria legislativa
     * estiver ligada nela (mesma regra da listagem). Documento de gabinete:
     * só quem está no contexto daquele gabinete.
     */
    public function view(User $user, KnowledgeDocument $document): bool
    {
        if ($document->isPlatform()) {
            return $user->isRoot() || $this->context->officeIdFor($user) !== null;
        }

        if ($document->isEntidadeWide()) {
            $gabinete = $this->gabinete($user);

            return $gabinete !== null
                && $gabinete->entidade_id === $document->entidade_id
                && $this->entidadeModules->isActive($gabinete->entidade_id, EntityModule::LegislativeSecretariat);
        }

        return $this->context->officeIdFor($user) === $document->gabinete_id;
    }

    /** Envio pelo gabinete: qualquer integrante no contexto dele, exceto o auditor. */
    public function create(User $user): bool
    {
        return $this->context->officeIdFor($user) !== null && $this->context->roleFor($user)->canWrite();
    }

    /**
     * Compartilhar com a entidade inteira: só a Secretaria legislativa, com o
     * módulo dela ativo (no gabinete e na entidade), e o auditor só lê — a
     * mesma regra de quem publica a agenda institucional
     * (`CompromissoInstitucionalPolicy::create`). Aqui o módulo entra na
     * Policy porque a rota de envio é a da Base de Conhecimento, não a da
     * Secretaria.
     */
    public function createEntidadeWide(User $user): bool
    {
        return $this->create($user)
            && $this->gabinete($user)?->tipo_gabinete === OfficeType::LegislativeSecretariat
            && $this->modules->isActive($this->context->officeIdFor($user), OfficeModule::LegislativeSecretariat);
    }

    public function createPlatform(User $user): bool
    {
        return $user->isRoot();
    }

    /**
     * Biblioteca da plataforma só é mantida pelo administrador. No gabinete,
     * remove quem enviou ou quem gerencia o gabinete. Compartilhado com a
     * entidade: só a Secretaria legislativa da mesma entidade — quem enviou
     * ou quem a gerencia; nenhum outro gabinete da Câmara.
     */
    public function delete(User $user, KnowledgeDocument $document): bool
    {
        if ($document->isPlatform()) {
            return $user->isRoot();
        }

        if ($document->isEntidadeWide()) {
            return $this->createEntidadeWide($user)
                && $this->gabinete($user)?->entidade_id === $document->entidade_id
                && ($document->enviado_por_id === $user->id
                    || $user->canManageGabinete((int) $this->context->officeIdFor($user)));
        }

        return $this->context->officeIdFor($user) === $document->gabinete_id
            && $this->context->roleFor($user)->canWrite()
            && ($document->enviado_por_id === $user->id
                || $user->canManageGabinete($document->gabinete_id));
    }

    /**
     * O gabinete em que a pessoa atua (`OfficeContext::officeFor()`), lido
     * sem o escopo global como em `CompromissoInstitucionalPolicy`: a regra
     * não pode depender de haver contexto resolvido.
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

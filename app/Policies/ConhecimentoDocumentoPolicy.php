<?php

namespace App\Policies;

use App\Enums\EntidadeModule;
use App\Enums\GabineteModule;
use App\Enums\GabineteType;
use App\Models\ConhecimentoDocumento;
use App\Models\Gabinete;
use App\Models\User;
use App\Services\Modules\EntidadeModuleManager;
use App\Services\Modules\GabineteModuleManager;

class ConhecimentoDocumentoPolicy
{
    public function __construct(
        private readonly GabineteModuleManager $modules,
        private readonly EntidadeModuleManager $entidadeModules,
    ) {}

    /**
     * Biblioteca da plataforma: administradores e qualquer pessoa em contexto
     * de gabinete (o módulo é checado na rota). Compartilhado com a entidade:
     * qualquer gabinete daquela entidade, enquanto a Secretaria legislativa
     * estiver ligada nela (mesma regra da listagem). Documento de gabinete:
     * só quem está no contexto daquele gabinete.
     */
    public function view(User $user, ConhecimentoDocumento $document): bool
    {
        if ($document->isPlatform()) {
            return $user->isRoot() || $user->gabinete_id !== null;
        }

        if ($document->isEntidadeWide()) {
            $gabinete = $this->gabinete($user);

            return $gabinete !== null
                && $gabinete->entidade_id === $document->entidade_id
                && $this->entidadeModules->isActive($gabinete->entidade_id, EntidadeModule::LegislativeSecretariat);
        }

        return $user->gabinete_id === $document->gabinete_id;
    }

    /** Envio pelo gabinete: qualquer integrante no contexto dele, exceto o auditor. */
    public function create(User $user): bool
    {
        return $user->gabinete_id !== null && $user->role->canWrite();
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
            && $this->gabinete($user)?->tipo_gabinete === GabineteType::LegislativeSecretariat
            && $this->modules->isActive($user->gabinete_id, GabineteModule::LegislativeSecretariat);
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
    public function delete(User $user, ConhecimentoDocumento $document): bool
    {
        if ($document->isPlatform()) {
            return $user->isRoot();
        }

        if ($document->isEntidadeWide()) {
            return $this->createEntidadeWide($user)
                && $this->gabinete($user)?->entidade_id === $document->entidade_id
                && ($document->enviado_por_id === $user->id
                    || $user->canManageGabinete((int) $user->gabinete_id));
        }

        return $user->gabinete_id === $document->gabinete_id
            && $user->role->canWrite()
            && ($document->enviado_por_id === $user->id
                || $user->canManageGabinete($document->gabinete_id));
    }

    /**
     * O gabinete em contexto (`users.gabinete_id`), lido sem o escopo global
     * como em `CompromissoInstitucionalPolicy`: a regra não pode depender de
     * haver contexto resolvido, e o id já é o do próprio usuário.
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

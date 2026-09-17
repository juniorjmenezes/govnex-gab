<?php

namespace App\Policies;

use App\Models\ConhecimentoDocumento;
use App\Models\User;

class ConhecimentoDocumentoPolicy
{
    /**
     * Biblioteca da plataforma: administradores e qualquer pessoa em contexto
     * de gabinete (o módulo é checado na rota). Documento de gabinete: só quem
     * está no contexto daquele gabinete.
     */
    public function view(User $user, ConhecimentoDocumento $document): bool
    {
        if ($document->isPlatform()) {
            return $user->isRoot() || $user->gabinete_id !== null;
        }

        return $user->gabinete_id === $document->gabinete_id;
    }

    /** Envio pelo gabinete: qualquer integrante no contexto dele. */
    public function create(User $user): bool
    {
        return $user->gabinete_id !== null;
    }

    public function createPlatform(User $user): bool
    {
        return $user->isRoot();
    }

    /**
     * Biblioteca da plataforma só é mantida pelo administrador. No gabinete,
     * remove quem enviou ou quem gerencia o gabinete.
     */
    public function delete(User $user, ConhecimentoDocumento $document): bool
    {
        if ($document->isPlatform()) {
            return $user->isRoot();
        }

        return $user->gabinete_id === $document->gabinete_id
            && ($document->enviado_por_id === $user->id
                || $user->canManageGabinete($document->gabinete_id));
    }
}

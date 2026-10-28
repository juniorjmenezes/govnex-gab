<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Services\Entidades\EntityInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pessoas e vínculos migraram para o Govnex Hub: a criação de convite novo
 * foi desligada (ver docs/INTEGRACAO_GOVNEX_HUB.md). Convites pendentes
 * anteriores ao corte continuam podendo ser aceitos normalmente
 * (EntidadeInvitationAcceptController).
 */
class EntidadeInvitationController extends Controller
{
    public function store(
        Request $request,
        Entity $entidade,
        EntityInvitationService $invitations,
    ): RedirectResponse {
        abort(403, 'Gerenciado no Govnex Hub — altere lá.');
    }
}

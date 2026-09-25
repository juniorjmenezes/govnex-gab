<?php

namespace App\Http\Controllers;

use App\Models\Entidade;
use App\Services\Entidades\EntidadeInvitationService;
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
        Entidade $entidade,
        EntidadeInvitationService $invitations,
    ): RedirectResponse {
        abort(403, 'Gerenciado no Govnex Hub — altere lá.');
    }
}

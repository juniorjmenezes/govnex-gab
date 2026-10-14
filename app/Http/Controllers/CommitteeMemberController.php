<?php

namespace App\Http\Controllers;

use App\Http\Requests\Committees\CommitteeMemberRequest;
use App\Models\ComissaoLegislativa;
use App\Models\ComissaoMembro;
use App\Services\Committees\CommitteeMembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Composição da comissão, só pela Secretaria legislativa (rota sob
 * `module:SECRETARIA_LEGISLATIVA`, Policy `update` da comissão). As regras de
 * domínio ficam em `CommitteeMembershipService`.
 */
class CommitteeMemberController extends Controller
{
    public function __construct(private readonly CommitteeMembershipService $membership) {}

    public function store(CommitteeMemberRequest $request, ComissaoLegislativa $comissao): RedirectResponse
    {
        $joinedAt = $request->validated('ingressou_em');

        $this->membership->add(
            $comissao,
            (int) $request->validated('gabinete_id'),
            $request->userId(),
            $request->role(),
            $joinedAt !== null ? CarbonImmutable::parse((string) $joinedAt) : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Membro incluído na comissão.']);

        return back();
    }

    public function update(
        CommitteeMemberRequest $request,
        ComissaoLegislativa $comissao,
        ComissaoMembro $membro,
    ): RedirectResponse {
        $this->ensureBelongs($comissao, $membro);
        $this->membership->update($membro, $request->userId(), $request->role());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Membro atualizado.']);

        return back();
    }

    public function destroy(ComissaoLegislativa $comissao, ComissaoMembro $membro): RedirectResponse
    {
        $this->authorize('update', $comissao);
        $this->ensureBelongs($comissao, $membro);
        $this->membership->remove($membro);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Membro removido da comissão.']);

        return back();
    }

    private function ensureBelongs(ComissaoLegislativa $comissao, ComissaoMembro $membro): void
    {
        abort_unless($membro->comissao_id === $comissao->id, 404);
    }
}

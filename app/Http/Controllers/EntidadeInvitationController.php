<?php

namespace App\Http\Controllers;

use App\Enums\AccessRole;
use App\Models\Entidade;
use App\Models\Gabinete;
use App\Services\Entidades\EntidadeInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EntidadeInvitationController extends Controller
{
    public function store(
        Request $request,
        Entidade $entidade,
        EntidadeInvitationService $invitations,
    ): RedirectResponse {
        abort_unless($request->user()->canManageEntidade($entidade->id), 403);
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'entidade_role' => ['required', Rule::enum(AccessRole::class)],
            'gabinete_id' => ['nullable', 'integer'],
            'papel_gabinete' => ['nullable', Rule::enum(AccessRole::class)],
            'delivery_mode' => ['required', Rule::in(['EMAIL', 'SENHA_TEMPORARIA'])],
        ]);
        $gabinete = isset($validated['gabinete_id'])
            ? Gabinete::withoutGlobalScopes()->whereKey($validated['gabinete_id'])->firstOrFail()
            : null;
        $result = $invitations->invite(
            $entidade,
            $gabinete,
            $validated['email'],
            AccessRole::from($validated['entidade_role']),
            isset($validated['papel_gabinete']) ? AccessRole::from($validated['papel_gabinete']) : null,
            $request->user(),
            $validated['delivery_mode'],
        );

        $request->session()->flash('success', 'Convite criado com validade de 48 horas.');
        if ($validated['delivery_mode'] === 'SENHA_TEMPORARIA') {
            $request->session()->flash('temporary_invitation_credential', $result['credential']);
        }

        return back();
    }
}

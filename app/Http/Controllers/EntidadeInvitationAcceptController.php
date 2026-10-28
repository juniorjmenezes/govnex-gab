<?php

namespace App\Http\Controllers;

use App\Models\EntityInvitation;
use App\Models\User;
use App\Services\Entidades\EntityInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EntidadeInvitationAcceptController extends Controller
{
    public function show(
        Request $request,
        string $credential,
        EntityInvitationService $invitations,
    ): Response {
        $invitation = $invitations->findPending($credential);
        $existingUser = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])
            ->exists();

        if ($existingUser && ! $request->user()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return Inertia::render('entity-invitations/show', [
            'credential' => $credential,
            'invitation' => [
                'entidade' => $invitation->entidade->nome,
                'gabinete' => $invitation->gabinete?->nome,
                'email' => $invitation->email,
                'expires_at' => $invitation->expira_em->toIso8601String(),
                'existing_user' => $existingUser,
            ],
        ]);
    }

    public function store(
        Request $request,
        string $credential,
        EntityInvitationService $invitations,
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:180'],
            'password' => ['nullable', 'string', 'min:12', 'confirmed'],
        ]);
        $user = $invitations->accept(
            $credential,
            $request->user(),
            $validated['name'] ?? null,
            $validated['password'] ?? null,
        );

        if (! $request->user()) {
            auth()->login($user);
            $request->session()->regenerate();
        }

        $invitation = EntityInvitation::query()
            ->with('entidade')
            ->where('token_hash', hash('sha256', $credential))
            ->firstOrFail();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Convite aceito.']);

        return redirect()->route('entidades.show', $invitation->entidade);
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $contact = $request->user()->whatsappContact()->first();

        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'whatsapp' => $contact ? [
                'status' => $contact->status->value,
                'last_four' => $contact->telefone_final,
                'declared_at' => $contact->declarado_em?->toIso8601String(),
                'has_current_consent' => $contact->hasCurrentConsent(),
            ] : null,
            'whatsappConsent' => [
                'version' => (string) config('whatsapp.consent.version'),
                'text' => (string) config('whatsapp.consent.text'),
            ],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perfil atualizado.']);

        return to_route('profile.edit');
    }
}

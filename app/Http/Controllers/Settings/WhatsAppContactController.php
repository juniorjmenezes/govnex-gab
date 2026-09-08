<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\WhatsAppContactService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class WhatsAppContactController extends Controller
{
    public function store(Request $request, WhatsAppContactService $contacts): RedirectResponse
    {
        $validated = $request->validate([
            'telefone' => ['required', 'string', 'min:10', 'max:20'],
            'aceite' => ['accepted'],
        ]);
        abort_unless($request->user()->gabinete_id !== null, 403);
        $contacts->declareForUser($request->user(), $validated['telefone'], $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'WhatsApp cadastrado e consentimento registrado.']);

        return to_route('profile.edit');
    }

    public function destroy(Request $request, WhatsAppContactService $contacts): RedirectResponse
    {
        $contact = WhatsAppContact::withoutGlobalScopes()
            ->where('usuario_id', $request->user()->id)
            ->where('gabinete_id', $request->user()->gabinete_id)
            ->firstOrFail();
        $contacts->revoke($contact, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Consentimento do WhatsApp revogado.']);

        return to_route('profile.edit');
    }
}

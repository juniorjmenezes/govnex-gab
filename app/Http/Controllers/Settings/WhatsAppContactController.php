<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\GabineteMembro;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\WhatsAppContactService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;
use RuntimeException;

final class WhatsAppContactController extends Controller
{
    public function store(Request $request, WhatsAppContactService $contacts): RedirectResponse
    {
        $validated = $request->validate([
            'telefone' => ['required', 'string', 'min:10', 'max:20'],
            'aceite' => ['accepted'],
        ]);
        $gabineteId = $request->user()->gabinete_id;
        // Um membro pausado no Time (`gabinete_membros.ativo = false`) não
        // pode se autodeclarar no WhatsApp — esse estado não aparece em
        // `users.is_active`, só no vínculo de equipe.
        abort_unless($gabineteId !== null && GabineteMembro::isActiveMember($request->user(), $gabineteId), 403);

        try {
            $contacts->declareForUser($request->user(), $validated['telefone'], $request->user());
        } catch (InvalidArgumentException|RuntimeException $exception) {
            // Telefone mal formado (InvalidArgumentException) ou segredo de
            // hash do WhatsApp não configurado (RuntimeException): as duas
            // já vêm com mensagem pronta para exibir, nunca uma exceção não
            // tratada por um clique de botão.
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }
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

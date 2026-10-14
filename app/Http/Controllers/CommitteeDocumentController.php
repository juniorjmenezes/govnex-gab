<?php

namespace App\Http\Controllers;

use App\Http\Requests\Committees\CommitteeDocumentRequest;
use App\Models\ComissaoLegislativa;
use App\Models\ConhecimentoDocumento;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Vincula a uma comissão um documento que já está na Base de Conhecimento,
 * compartilhado com a Câmara pela Secretaria legislativa. Não recebe
 * arquivo: o envio continua sendo o da Base de Conhecimento.
 */
class CommitteeDocumentController extends Controller
{
    public function store(CommitteeDocumentRequest $request, ComissaoLegislativa $comissao): RedirectResponse
    {
        $type = $request->validated('tipo_vinculo');

        try {
            $comissao->documentos()->attach((int) $request->validated('documento_id'), [
                'tipo_vinculo' => $type !== null && $type !== '' ? $type : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Duas requisições simultâneas: o índice único decide.
            throw ValidationException::withMessages([
                'documento_id' => 'Este documento já está vinculado à comissão.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Documento vinculado à comissão.']);

        return back();
    }

    public function destroy(ComissaoLegislativa $comissao, ConhecimentoDocumento $documento): RedirectResponse
    {
        $this->authorize('update', $comissao);
        abort_unless($comissao->documentos()->detach($documento->id) > 0, 404);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Documento desvinculado da comissão.']);

        return back();
    }
}

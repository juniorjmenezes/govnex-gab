<?php

namespace App\Http\Controllers;

use App\Http\Requests\Demands\StoreDemandAttachmentsRequest;
use App\Models\Demanda;
use App\Models\DemandaAnexo;
use App\Services\Demands\DemandAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DemandAttachmentController extends Controller
{
    public function store(
        StoreDemandAttachmentsRequest $request,
        Demanda $demanda,
        DemandAttachmentService $attachments,
    ): RedirectResponse {
        $files = $request->allFiles()['arquivos'] ?? [];
        abort_unless(is_array($files), 422);

        $attachments->store($demanda, array_values($files), $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Arquivos anexados com segurança.']);

        return to_route('demands.show', $demanda);
    }

    public function download(Demanda $demanda, DemandaAnexo $anexo): StreamedResponse
    {
        $this->guardNestedAttachment($demanda, $anexo);
        $this->authorize('view', $anexo);

        abort_unless(Storage::disk($anexo->disk)->exists($anexo->caminho), 404);

        return Storage::disk($anexo->disk)->download($anexo->caminho, $anexo->nome_original, [
            'Content-Type' => $anexo->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(Demanda $demanda, DemandaAnexo $anexo): StreamedResponse
    {
        $this->guardNestedAttachment($demanda, $anexo);
        $this->authorize('view', $anexo);
        abort_unless($anexo->imagem, 404);
        abort_unless(Storage::disk($anexo->disk)->exists($anexo->caminho), 404);

        return Storage::disk($anexo->disk)->response($anexo->caminho, $anexo->nome_original, [
            'Content-Type' => $anexo->mime_type,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(
        Demanda $demanda,
        DemandaAnexo $anexo,
        DemandAttachmentService $attachments,
    ): RedirectResponse {
        $this->guardNestedAttachment($demanda, $anexo);
        $this->authorize('delete', $anexo);
        $attachments->delete($anexo, request()->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Anexo removido.']);

        return to_route('demands.show', $demanda);
    }

    private function guardNestedAttachment(Demanda $demand, DemandaAnexo $attachment): void
    {
        abort_unless($attachment->demanda_id === $demand->id, 404);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\KnowledgeBase\RecordKnowledgeReadingRequest;
use App\Http\Requests\KnowledgeBase\StoreKnowledgeDocumentRequest;
use App\Models\ConhecimentoDocumento;
use App\Models\ConhecimentoLeitura;
use App\Services\KnowledgeBase\KnowledgeDocumentService;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Base de Conhecimento no contexto de um gabinete: biblioteca da plataforma
 * mais os PDFs enviados pela própria equipe.
 */
class KnowledgeBaseController extends Controller
{
    public function __construct(private readonly KnowledgeDocumentService $documents) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $search = trim((string) $request->query('q', ''));
        $page = ConhecimentoDocumento::query()
            ->visibleTo((int) $user->gabinete_id)
            ->with('enviadoPor:id,name')
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->where('titulo', 'like', "%{$search}%")
                    ->orWhere('descricao', 'like', "%{$search}%"),
            ))
            ->latest()
            ->paginate(PerPage::resolve($request, 20))
            ->withQueryString();
        $readings = ConhecimentoLeitura::query()
            ->where('usuario_id', $user->id)
            ->whereIn('documento_id', $page->pluck('id'))
            ->get()
            ->keyBy('documento_id');

        return Inertia::render('knowledge/index', [
            'scope' => 'gabinete',
            'documents' => $page->through(fn (ConhecimentoDocumento $document): array => $this->documents
                ->summary($document, $user, $readings->get($document->id))),
            'filters' => ['q' => $search],
            'canUpload' => $user->can('create', ConhecimentoDocumento::class),
            'maxUploadMb' => intdiv(StoreKnowledgeDocumentRequest::MAX_KILOBYTES, 1024),
        ]);
    }

    public function store(StoreKnowledgeDocumentRequest $request): RedirectResponse
    {
        $this->authorize('create', ConhecimentoDocumento::class);
        $this->documents->store(
            $request->file('arquivo'),
            $request->validated('titulo'),
            $request->validated('descricao'),
            (int) $request->user()->gabinete_id,
            $request->user(),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Documento adicionado à Base de Conhecimento.']);

        return to_route('knowledge.index');
    }

    /** Dados de um documento para abrir o leitor em modal na listagem. */
    public function show(Request $request, ConhecimentoDocumento $documento): JsonResponse
    {
        $this->authorize('view', $documento);
        $reading = $documento->leituras()->where('usuario_id', $request->user()->id)->first();

        return response()->json([
            'document' => $this->documents->summary($documento->load('enviadoPor:id,name'), $request->user(), $reading),
        ]);
    }

    public function file(ConhecimentoDocumento $documento): StreamedResponse
    {
        $this->authorize('view', $documento);

        return $this->documents->stream($documento);
    }

    public function progress(RecordKnowledgeReadingRequest $request, ConhecimentoDocumento $documento): RedirectResponse
    {
        $this->documents->recordPage(
            $documento,
            $request->user(),
            (int) $request->validated('pagina'),
            (int) $request->validated('total_paginas'),
        );

        return back();
    }

    public function destroy(ConhecimentoDocumento $documento): RedirectResponse
    {
        $this->authorize('delete', $documento);
        $this->documents->delete($documento);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Documento removido.']);

        return to_route('knowledge.index');
    }
}

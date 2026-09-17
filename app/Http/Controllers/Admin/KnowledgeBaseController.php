<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
 * Biblioteca da plataforma: PDFs mantidos pelo administrador e exibidos a
 * todos os gabinetes com a Base de Conhecimento ativa.
 */
class KnowledgeBaseController extends Controller
{
    public function __construct(private readonly KnowledgeDocumentService $documents) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $search = trim((string) $request->query('q', ''));
        $page = ConhecimentoDocumento::query()
            ->whereNull('gabinete_id')
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
            'scope' => 'plataforma',
            'documents' => $page->through(fn (ConhecimentoDocumento $document): array => $this->documents
                ->summary($document, $user, $readings->get($document->id))),
            'filters' => ['q' => $search],
            'canUpload' => true,
            'maxUploadMb' => intdiv(StoreKnowledgeDocumentRequest::MAX_KILOBYTES, 1024),
        ]);
    }

    public function store(StoreKnowledgeDocumentRequest $request): RedirectResponse
    {
        $this->authorize('createPlatform', ConhecimentoDocumento::class);
        $this->documents->store(
            $request->file('arquivo'),
            $request->validated('titulo'),
            $request->validated('descricao'),
            null,
            $request->user(),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Documento adicionado à biblioteca da plataforma.']);

        return to_route('admin.knowledge.index');
    }

    /** Dados de um documento para abrir o leitor em modal na listagem. */
    public function show(Request $request, ConhecimentoDocumento $documento): JsonResponse
    {
        $this->ensurePlatform($documento);
        $reading = $documento->leituras()->where('usuario_id', $request->user()->id)->first();

        return response()->json([
            'document' => $this->documents->summary($documento->load('enviadoPor:id,name'), $request->user(), $reading),
        ]);
    }

    public function file(ConhecimentoDocumento $documento): StreamedResponse
    {
        $this->ensurePlatform($documento);

        return $this->documents->stream($documento);
    }

    public function progress(RecordKnowledgeReadingRequest $request, ConhecimentoDocumento $documento): RedirectResponse
    {
        $this->ensurePlatform($documento);
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
        $this->ensurePlatform($documento);
        $this->authorize('delete', $documento);
        $this->documents->delete($documento);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Documento removido da biblioteca.']);

        return to_route('admin.knowledge.index');
    }

    /** A área administrativa trata só da biblioteca da plataforma. */
    private function ensurePlatform(ConhecimentoDocumento $document): void
    {
        abort_unless($document->isPlatform(), 404);
    }
}

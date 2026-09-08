<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRssSourceRequest;
use App\Jobs\FetchRssSource;
use App\Models\FonteRss;
use App\Models\NoticiaRss;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RssSourceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isRoot(), 403);

        $counts = NoticiaRss::query()
            ->selectRaw('fonte_rss_id, count(*) as total')
            ->groupBy('fonte_rss_id')
            ->pluck('total', 'fonte_rss_id');

        return Inertia::render('admin/rss-sources/index', [
            'sources' => FonteRss::query()
                ->orderBy('nome')
                ->get([
                    'id', 'nome', 'url', 'ativo', 'ultima_coleta_em',
                    'itens_importados', 'ultimo_erro', 'ultimo_erro_em',
                ])
                ->map(fn (FonteRss $fonte): array => [
                    ...$fonte->only([
                        'id', 'nome', 'url', 'ativo', 'itens_importados', 'ultimo_erro',
                    ]),
                    'ultima_coleta_em' => $fonte->ultima_coleta_em?->toIso8601String(),
                    'ultimo_erro_em' => $fonte->ultimo_erro_em?->toIso8601String(),
                    'noticias' => (int) ($counts[$fonte->id] ?? 0),
                ]),
        ]);
    }

    public function store(StoreRssSourceRequest $request): RedirectResponse
    {
        $fonte = FonteRss::query()->create($request->validated());

        // Primeira coleta imediata: sem isso a fonte fica vazia até a
        // próxima janela de 15 minutos e parece que não funcionou.
        FetchRssSource::dispatch($fonte->id)->onQueue('rss');

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fonte cadastrada. A primeira coleta foi enfileirada.']);

        return to_route('admin.rss-sources.index');
    }

    public function update(StoreRssSourceRequest $request, FonteRss $fonteRss): RedirectResponse
    {
        $fonteRss->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fonte atualizada.']);

        return to_route('admin.rss-sources.index');
    }

    public function destroy(Request $request, FonteRss $fonteRss): RedirectResponse
    {
        abort_unless($request->user()->isRoot(), 403);

        $fonteRss->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fonte removida.']);

        return to_route('admin.rss-sources.index');
    }

    /** Coleta sob demanda, para o admin conferir um feed recém-cadastrado. */
    public function collect(Request $request, FonteRss $fonteRss): RedirectResponse
    {
        abort_unless($request->user()->isRoot(), 403);

        FetchRssSource::dispatch($fonteRss->id)->onQueue('rss');

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Coleta enfileirada.']);

        return to_route('admin.rss-sources.index');
    }
}

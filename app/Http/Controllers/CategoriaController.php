<?php

namespace App\Http\Controllers;

use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use App\Models\Categoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoriaController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Categoria::class);

        return Inertia::render('categories/index', [
            'canManage' => $request->user()->can('create', Categoria::class),
            'categories' => Categoria::query()
                ->select(['id', 'nome', 'descricao', 'icone', 'cor_semantica', 'ativo'])
                ->orderBy('nome')
                ->paginate(20),
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        Categoria::query()->create($request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria cadastrada.']);

        return to_route('categories.index');
    }

    public function update(UpdateCategoryRequest $request, Categoria $categoria): RedirectResponse
    {
        $categoria->update($request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria atualizada.']);

        return to_route('categories.index');
    }

    public function destroy(Categoria $categoria): RedirectResponse
    {
        $this->authorize('delete', $categoria);
        $categoria->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria excluída.']);

        return to_route('categories.index');
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use App\Models\Category;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Category::class);

        return Inertia::render('categories/index', [
            'canManage' => $request->user()->can('create', Category::class),
            'categories' => Category::query()
                ->select(['id', 'nome', 'descricao', 'icone', 'cor_semantica', 'ativo'])
                ->orderBy('nome')
                ->paginate(PerPage::resolve($request, 20))
                ->withQueryString(),
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        Category::query()->create($request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria cadastrada.']);

        return to_route('categories.index');
    }

    public function update(UpdateCategoryRequest $request, Category $categoria): RedirectResponse
    {
        $categoria->update($request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria atualizada.']);

        return to_route('categories.index');
    }

    public function destroy(Category $categoria): RedirectResponse
    {
        $this->authorize('delete', $categoria);
        $categoria->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria excluída.']);

        return to_route('categories.index');
    }
}

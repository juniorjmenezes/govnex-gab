<?php

namespace App\Http\Controllers;

use App\Http\Requests\Neighborhoods\StoreNeighborhoodRequest;
use App\Http\Requests\Neighborhoods\UpdateNeighborhoodRequest;
use App\Models\Entity;
use App\Models\EntityNeighborhood;
use App\Models\Neighborhood;
use App\Models\Office;
use App\Support\PerPage;
use App\Tenancy\EntityContext;
use App\Tenancy\OfficeContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class NeighborhoodController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Neighborhood::class);
        $gabinete = $this->currentUnit($request);
        $entidade = $this->currentEntidade($gabinete);

        return Inertia::render('neighborhoods/index', [
            'canManage' => $request->user()->can('create', Neighborhood::class),
            'officeLocation' => $gabinete->only(['municipio', 'estado']),
            'neighborhoods' => Neighborhood::query()
                ->select(['id', 'entidade_bairro_id', 'nome', 'municipio', 'estado', 'ativo'])
                ->orderBy('nome')
                ->paginate(PerPage::resolve($request, 20))
                ->withQueryString(),
            'sharedNeighborhoods' => $entidade->bairros()
                ->where('ativo', true)
                ->whereDoesntHave('bairrosLocais', fn ($query) => $query
                    ->where('gabinete_id', $gabinete->id))
                ->orderBy('nome')
                ->get(['id', 'nome', 'municipio', 'estado'])
                ->map(fn (EntityNeighborhood $neighborhood): array => [
                    'id' => $neighborhood->id,
                    'name' => $neighborhood->nome,
                    'city' => $neighborhood->municipio,
                    'state' => $neighborhood->estado,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreNeighborhoodRequest $request): RedirectResponse
    {
        $gabinete = $this->currentUnit($request);
        $entidade = $this->currentEntidade($gabinete);
        $validated = $request->validated();

        DB::transaction(function () use ($request, $gabinete, $entidade, $validated): void {
            $reference = $entidade->bairros()->firstOrCreate([
                'nome' => $validated['nome'],
                'municipio' => $gabinete->municipio,
                'estado' => $gabinete->estado,
            ], [
                'ativo' => true,
                'criado_por' => $request->user()->id,
            ]);

            Neighborhood::query()->create([
                ...$validated,
                'entidade_bairro_id' => $reference->id,
                'municipio' => $gabinete->municipio,
                'estado' => $gabinete->estado,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bairro cadastrado.']);

        return back();
    }

    public function importReference(
        Request $request,
        EntityNeighborhood $reference,
    ): RedirectResponse {
        $this->authorize('create', Neighborhood::class);
        $gabinete = $this->currentUnit($request);
        $entidade = $this->currentEntidade($gabinete);

        abort_unless($reference->entidade_id === $entidade->id && $reference->ativo, 404);

        $existing = Neighborhood::withTrashed()
            ->where(fn ($query) => $query
                ->where('entidade_bairro_id', $reference->id)
                ->orWhere(fn ($nested) => $nested
                    ->where('nome', $reference->nome)
                    ->where('municipio', $reference->municipio)
                    ->where('estado', $reference->estado)))
            ->first();

        if ($existing) {
            $existing->forceFill([
                'entidade_bairro_id' => $reference->id,
                'ativo' => true,
                'deleted_at' => null,
            ])->save();
        } else {
            Neighborhood::query()->create([
                'entidade_bairro_id' => $reference->id,
                'nome' => $reference->nome,
                'municipio' => $reference->municipio,
                'estado' => $reference->estado,
                'ativo' => true,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Referência adicionada ao gabinete.']);

        return back();
    }

    public function update(UpdateNeighborhoodRequest $request, Neighborhood $bairro): RedirectResponse
    {
        $gabinete = $this->currentUnit($request);
        $entidade = $this->currentEntidade($gabinete);
        $validated = $request->validated();

        DB::transaction(function () use ($request, $bairro, $gabinete, $entidade, $validated): void {
            $reference = $entidade->bairros()->firstOrCreate([
                'nome' => $validated['nome'],
                'municipio' => $gabinete->municipio,
                'estado' => $gabinete->estado,
            ], [
                'ativo' => true,
                'criado_por' => $request->user()->id,
            ]);

            $bairro->update([
                ...$validated,
                'entidade_bairro_id' => $reference->id,
                'municipio' => $gabinete->municipio,
                'estado' => $gabinete->estado,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bairro atualizado.']);

        return back();
    }

    public function destroy(Request $request, Neighborhood $bairro): RedirectResponse
    {
        $this->authorize('delete', $bairro);
        $bairro->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bairro excluído.']);

        $gabinete = $this->currentUnit($request);

        return to_route('context.neighborhoods.index', [
            'entidade' => $this->currentEntidade($gabinete),
            'gabinete' => $gabinete,
        ]);
    }

    private function currentUnit(Request $request): Office
    {
        return app(OfficeContext::class)->gabinete()
            ?? app(OfficeContext::class)->requireOfficeFor($request->user());
    }

    private function currentEntidade(Office $gabinete): Entity
    {
        return app(EntityContext::class)->entidade()
            ?? $gabinete->entidade()->firstOrFail();
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\Neighborhoods\StoreNeighborhoodRequest;
use App\Http\Requests\Neighborhoods\UpdateNeighborhoodRequest;
use App\Models\Bairro;
use App\Models\Entidade;
use App\Models\EntidadeBairro;
use App\Models\Gabinete;
use App\Support\PerPage;
use App\Tenancy\EntidadeContext;
use App\Tenancy\GabineteContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BairroController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Bairro::class);
        $gabinete = $this->currentUnit($request);
        $entidade = $this->currentEntidade($gabinete);

        return Inertia::render('neighborhoods/index', [
            'canManage' => $request->user()->can('create', Bairro::class),
            'officeLocation' => $gabinete->only(['municipio', 'estado']),
            'neighborhoods' => Bairro::query()
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
                ->map(fn (EntidadeBairro $neighborhood): array => [
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

            Bairro::query()->create([
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
        EntidadeBairro $reference,
    ): RedirectResponse {
        $this->authorize('create', Bairro::class);
        $gabinete = $this->currentUnit($request);
        $entidade = $this->currentEntidade($gabinete);

        abort_unless($reference->entidade_id === $entidade->id && $reference->ativo, 404);

        $existing = Bairro::withTrashed()
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
            Bairro::query()->create([
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

    public function update(UpdateNeighborhoodRequest $request, Bairro $bairro): RedirectResponse
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

    public function destroy(Request $request, Bairro $bairro): RedirectResponse
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

    private function currentUnit(Request $request): Gabinete
    {
        return app(GabineteContext::class)->gabinete()
            ?? $request->user()->gabinete()->firstOrFail();
    }

    private function currentEntidade(Gabinete $gabinete): Entidade
    {
        return app(EntidadeContext::class)->entidade()
            ?? $gabinete->entidade()->firstOrFail();
    }
}

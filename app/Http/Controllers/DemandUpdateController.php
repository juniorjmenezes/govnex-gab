<?php

namespace App\Http\Controllers;

use App\Actions\Demands\CreateDemandUpdate;
use App\Http\Requests\Demands\StoreDemandUpdateRequest;
use App\Models\Demand;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class DemandUpdateController extends Controller
{
    public function store(
        StoreDemandUpdateRequest $request,
        Demand $demanda,
        CreateDemandUpdate $action,
    ): RedirectResponse {
        $files = array_values($request->file('arquivos', []));
        $action->handle($demanda, $request->user(), $request->validated('texto'), $files);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Atualização adicionada.']);

        return to_route('demands.show', $demanda);
    }
}

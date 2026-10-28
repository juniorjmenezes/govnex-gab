<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePartyColorRequest;
use App\Http\Requests\Admin\UpdatePartyColorRequest;
use App\Models\PartyColor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PartyColorController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isRoot(), 403);

        return Inertia::render('admin/parties/index', [
            'parties' => PartyColor::query()
                ->orderBy('sigla')
                ->get(['id', 'sigla', 'cor']),
        ]);
    }

    public function store(StorePartyColorRequest $request): RedirectResponse
    {
        PartyColor::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cor do partido cadastrada.']);

        return to_route('admin.party-colors.index');
    }

    public function update(UpdatePartyColorRequest $request, PartyColor $partidoCor): RedirectResponse
    {
        $partidoCor->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cor do partido atualizada.']);

        return to_route('admin.party-colors.index');
    }

    public function destroy(Request $request, PartyColor $partidoCor): RedirectResponse
    {
        abort_unless($request->user()->isRoot(), 403);

        $partidoCor->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cor do partido removida.']);

        return to_route('admin.party-colors.index');
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\Demands\CreateReferral;
use App\Actions\Demands\RegisterReferralResponse;
use App\Http\Requests\Demands\RegisterReferralResponseRequest;
use App\Http\Requests\Demands\StoreDemandReferralRequest;
use App\Models\Demanda;
use App\Models\DemandaEvento;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class DemandReferralController extends Controller
{
    public function store(
        StoreDemandReferralRequest $request,
        Demanda $demanda,
        CreateReferral $action,
    ): RedirectResponse {
        $files = array_values($request->file('arquivos', []));
        $action->handle($demanda, $request->safe()->except('arquivos'), $request->user(), $files);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Encaminhamento registrado.']);

        return to_route('demands.show', $demanda);
    }

    public function respond(
        RegisterReferralResponseRequest $request,
        Demanda $demanda,
        RegisterReferralResponse $action,
    ): RedirectResponse {
        $referralId = $request->validated('encaminhamento_id');
        $referral = $referralId
            ? DemandaEvento::query()->where('demanda_id', $demanda->id)->where('id', $referralId)->first()
            : null;
        $files = array_values($request->file('arquivos', []));

        $action->handle($demanda, $request->user(), $request->validated('descricao'), $referral, $files);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Retorno registrado.']);

        return to_route('demands.show', $demanda);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateGabineteSettingsRequest;
use App\Models\Bairro;
use App\Models\Configuracao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class GabineteSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $gabinete = $request->user()->gabinete()->with('candidatoTitular')->firstOrFail();
        $configuracao = Configuracao::query()->first() ?? new Configuracao;
        $configuracao->setAttribute('gabinete_id', $gabinete->id);
        $this->authorize('view', $configuracao);
        $candidate = $gabinete->candidatoTitular;

        return Inertia::render('office-settings/edit', [
            'office' => $gabinete->only([
                'id',
                'nome',
                'vereador_nome',
                'numero_eleitoral',
                'municipio',
                'estado',
                'timezone',
                'telefone',
                'email',
                'endereco',
                'numero',
                'bairro',
                'cep',
                'logo_path',
                'cor_principal',
                'formato_protocolo',
                'cabecalho_relatorios',
            ]),
            'settings' => $configuracao->only(['partido', 'legislatura']),
            'canUpdate' => $request->user()->can('update', $configuracao),
            // O número eleitoral e o candidato titular são definidos pela
            // administração da plataforma (dados oficiais do TSE usados para
            // vincular a votação do gabinete); aqui só exibimos se ele já foi
            // localizado no TSE, sem permitir edição.
            'electoralCandidate' => [
                'matched' => $candidate !== null,
                'name' => $candidate?->nome_urna,
                'party' => $candidate?->partido_sigla,
            ],
        ]);
    }

    public function update(UpdateGabineteSettingsRequest $request): RedirectResponse
    {
        $gabinete = $request->user()->gabinete()->firstOrFail();
        $configuracao = Configuracao::query()->first() ?? new Configuracao;
        $configuracao->setAttribute('gabinete_id', $gabinete->id);
        $this->authorize('update', $configuracao);

        $validated = $request->validated();
        $oldLogo = $gabinete->logo_path;
        $removeLogo = (bool) ($validated['remover_logo'] ?? false);
        $useDefaultColor = (bool) ($validated['usar_cor_padrao'] ?? false);
        $newLogo = $request->file('logo')?->store("gabinetes/{$gabinete->id}", 'public');

        DB::transaction(function () use ($gabinete, $validated, $newLogo, $removeLogo, $useDefaultColor): void {
            $gabinete->update([
                ...collect($validated)->only([
                    'nome',
                    'vereador_nome',
                    'municipio',
                    'estado',
                    'timezone',
                    'telefone',
                    'email',
                    'endereco',
                    'numero',
                    'complemento',
                    'bairro',
                    'cep',
                    'formato_protocolo',
                    'cabecalho_relatorios',
                ])->all(),
                'cor_principal' => $useDefaultColor ? null : ($validated['cor_principal'] ?? null),
                ...($newLogo
                    ? ['logo_path' => $newLogo]
                    : ($removeLogo ? ['logo_path' => null] : [])),
            ]);

            Bairro::withoutGlobalScopes()
                ->where('gabinete_id', $gabinete->id)
                ->update([
                    'municipio' => $gabinete->municipio,
                    'estado' => $gabinete->estado,
                ]);

            Configuracao::query()->updateOrCreate([], [
                'partido' => $validated['partido'] ?? null,
                'legislatura' => $validated['legislatura'] ?? null,
            ]);
        });

        if ($oldLogo && ($newLogo || $removeLogo)) {
            Storage::disk('public')->delete($oldLogo);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Configurações do gabinete atualizadas.']);

        return to_route('office-settings.edit');
    }
}

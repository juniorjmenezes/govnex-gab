<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateOfficeSettingsRequest;
use App\Models\Neighborhood;
use App\Models\Setting;
use App\Tenancy\OfficeContext;
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
        $gabinete = app(OfficeContext::class)->requireOfficeFor($request->user())->loadMissing('candidatoTitular');
        $configuracao = Setting::query()->first() ?? new Setting;
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
            // Nome de gabinete ligado ao Govnex Hub é alterado lá.
            'nameManagedByHub' => $gabinete->hub_unidade_id !== null,
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

    public function update(UpdateOfficeSettingsRequest $request): RedirectResponse
    {
        $gabinete = app(OfficeContext::class)->requireOfficeFor($request->user());
        $configuracao = Setting::query()->first() ?? new Setting;
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

            Neighborhood::withoutGlobalScopes()
                ->where('gabinete_id', $gabinete->id)
                ->update([
                    'municipio' => $gabinete->municipio,
                    'estado' => $gabinete->estado,
                ]);

            Setting::query()->updateOrCreate([], [
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

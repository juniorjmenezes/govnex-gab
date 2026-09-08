<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateGovnexApiIntegrationRequest;
use App\Models\IntegracaoGovnexApi;
use App\Services\Politics\Tse\GovnexApiSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Configuração da integração com a GOVNEX API — de plataforma, não de
 * gabinete: a mesma URL e chave atendem todas as sincronizações globais de
 * eleitorado. Restrito ao root pelo middleware `root` da rota.
 */
class GovnexApiIntegrationController extends Controller
{
    public function edit(GovnexApiSettings $settings): Response
    {
        $record = $settings->record();

        return Inertia::render('admin/integrations/govnex-api', [
            'integration' => [
                'url' => $settings->url(),
                // Nunca devolvemos a chave: só se ela existe e como termina,
                // o suficiente para conferir qual está em uso sem expor o
                // segredo a quem abrir a tela.
                'has_key' => $settings->key() !== null,
                'key_hint' => $this->keyHint($settings->key()),
                'from_env' => $record === null,
                'verified_at' => $record?->verificada_em?->toIso8601String(),
                'verified_result' => $record?->verificado_resultado,
                'verified_detail' => $record?->verificado_detalhe,
                'updated_by' => $record?->atualizadoPor?->name,
                'updated_at' => $record?->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(
        UpdateGovnexApiIntegrationRequest $request,
        GovnexApiSettings $settings,
    ): RedirectResponse {
        $record = $settings->record() ?? new IntegracaoGovnexApi;
        $chave = $request->validated('chave');

        $record->fill([
            'url' => rtrim((string) $request->validated('url'), '/'),
            'atualizado_por_id' => $request->user()->id,
        ]);

        // Campo em branco preserva a chave atual — é o que permite editar só
        // a URL sem precisar redigitar o segredo, que a tela nunca exibe.
        if (filled($chave)) {
            $record->chave = $chave;
        } elseif ($request->boolean('remover_chave')) {
            $record->chave = null;
        }

        $record->save();
        $settings->forget();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Integração atualizada. Use “Testar conexão” para confirmar o acesso.',
        ]);

        return back();
    }

    /**
     * Bate no catálogo da API com a configuração salva e registra o
     * resultado, para que a tela mostre o estado da última verificação em
     * vez de exigir que alguém abra o log da fila.
     */
    public function test(Request $request, GovnexApiSettings $settings): RedirectResponse
    {
        $record = $settings->record();
        [$result, $detail] = $this->probe($settings);

        if ($record !== null) {
            $record->forceFill([
                'verificada_em' => now(),
                'verificado_resultado' => $result,
                'verificado_detalhe' => $detail,
            ])->save();
            $settings->forget();
        }

        Inertia::flash('toast', [
            'type' => $result === 'ok' ? 'success' : 'error',
            'message' => $detail,
        ]);

        return back();
    }

    /** @return array{0: string, 1: string} */
    private function probe(GovnexApiSettings $settings): array
    {
        $key = $settings->key();

        try {
            $response = Http::acceptJson()
                ->when($key !== null, fn ($request) => $request->withHeaders(['X-Api-Key' => $key]))
                ->timeout(15)
                ->get($settings->url().'/sources');

            if ($response->status() === 401) {
                return ['chave_invalida', 'A GOVNEX API recusou a chave (401). Gere outra em Chaves de API e cole aqui.'];
            }

            $response->throw();

            $sources = is_array($response->json('data')) ? count($response->json('data')) : 0;

            return ['ok', $key !== null
                ? "Conexão autenticada. {$sources} fonte(s) no catálogo."
                : "Conexão pública sem chave. {$sources} fonte(s) no catálogo, com limite de consumidor anônimo."];
        } catch (ConnectionException) {
            return ['inacessivel', 'Não foi possível alcançar a GOVNEX API na URL informada.'];
        } catch (RequestException $exception) {
            return ['erro', 'A GOVNEX API respondeu com erro '.$exception->response->status().'.'];
        } catch (Throwable $exception) {
            report($exception);

            return ['erro', 'Falha ao consultar a GOVNEX API: '.$exception->getMessage()];
        }
    }

    private function keyHint(?string $key): ?string
    {
        if ($key === null || mb_strlen($key) < 8) {
            return null;
        }

        return '…'.mb_substr($key, -6);
    }
}

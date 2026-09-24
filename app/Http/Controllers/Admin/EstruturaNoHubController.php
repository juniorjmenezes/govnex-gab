<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Criação local de entidade e gabinete, desligada.
 *
 * O Govnex Hub é o único ponto de criação de estrutura; o que ele cria para o
 * GAB nasce aqui pelo webhook (`HubEstruturaSyncService`). As rotas antigas
 * (`admin.entities.create|store`, `admin.offices.create|store`) continuam
 * existindo só para responder com a orientação, em vez de 404 para quem tem o
 * endereço salvo. A edição dos campos de domínio do GAB continua.
 */
class EstruturaNoHubController extends Controller
{
    public const MENSAGEM = 'A estrutura é criada no Govnex Hub.';

    public function __invoke(Request $request): RedirectResponse
    {
        Log::info('Tentativa de criar estrutura localmente no GAB recusada.', [
            'rota' => $request->route()?->getName(),
            'usuario_id' => $request->user()?->id,
        ]);

        abort_unless($request->isMethod('GET'), 403, self::MENSAGEM);

        Inertia::flash('toast', ['type' => 'error', 'message' => self::MENSAGEM.' Entidades e gabinetes nascem aqui automaticamente.']);

        return to_route('admin.offices.index');
    }
}

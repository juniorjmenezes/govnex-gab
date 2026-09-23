<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Hub\HubProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * Login por SSO no Govnex Hub (decisão #1 e #2).
 *
 * Este é o caminho padrão de entrada no GAB. O login local do Fortify continua
 * de pé, mas restrito a `root` (decisão #6): é o acesso administrativo de
 * emergência para quando o Hub estiver fora do ar.
 */
class HubAuthController extends Controller
{
    /** Manda a pessoa ao Hub. Estado e PKCE ficam na sessão. */
    public function redirect(): SymfonyRedirectResponse|RedirectResponse
    {
        if (! $this->configurado()) {
            return redirect()->route('login')
                ->withErrors(['email' => 'O acesso pelo Govnex Hub ainda não está configurado neste ambiente.']);
        }

        try {
            return Socialite::driver('hub')->redirect();
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('login')
                ->withErrors(['email' => 'Não foi possível iniciar o acesso pelo Govnex Hub.']);
        }
    }

    /**
     * Volta do Hub: troca o código, espelha a pessoa e os vínculos, e abre a
     * sessão.
     */
    public function callback(Request $request, HubProvisioningService $provisioning): RedirectResponse
    {
        if (! $this->configurado()) {
            return redirect()->route('login')
                ->withErrors(['email' => 'O acesso pelo Govnex Hub ainda não está configurado neste ambiente.']);
        }

        // O Hub sinaliza recusa do jeito do OAuth2, não com erro HTTP.
        if ($request->filled('error')) {
            return redirect()->route('login')
                ->withErrors(['email' => 'O acesso pelo Govnex Hub foi cancelado.']);
        }

        try {
            $hubUser = Socialite::driver('hub')->user();
            $user = $provisioning->provisionarDoLogin($hubUser);
        } catch (Throwable $exception) {
            Log::warning('Falha no retorno do SSO do Govnex Hub.', ['erro' => $exception->getMessage()]);

            return redirect()->route('login')->withErrors([
                'email' => $exception instanceof \RuntimeException
                    ? $exception->getMessage()
                    : 'Não foi possível concluir o acesso pelo Govnex Hub.',
            ]);
        }

        auth()->login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function configurado(): bool
    {
        return filled(config('services.hub.base_url'))
            && filled(config('services.hub.client_id'))
            && filled(config('services.hub.client_secret'));
    }
}

<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Auth\LocalRootLogin;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    /**
     * Login local por senha, restrito a `root`.
     *
     * Com o SSO do Govnex Hub como caminho padrão (decisão #2, corte direto), o
     * login local deixa de ser a porta de entrada e passa a ser só o acesso
     * administrativo de emergência de quando o Hub estiver fora do ar
     * (decisões #6 e #10). A infraestrutura do Fortify — senha, 2FA, passkeys,
     * redefinição — continua inteira: o que muda é quem pode usá-la.
     *
     * A restrição fica aqui, e não numa rota nova, porque é a única passagem
     * obrigatória de todos os fluxos de senha do Fortify.
     *
     * Contingência (PLATFORM.md §4): com o Hub configurado, só funciona com
     * `GEP_ROOT_LOGIN_ENABLED` ligada (`LocalRootLogin::enabled()`). Recusa
     * sempre com a mesma mensagem genérica do Fortify, sem revelar se a conta
     * existe. Tentativas contra conta root são auditadas.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = Str::lower((string) $request->input(Fortify::username()));
            $user = User::query()->where('email', $email)->first();
            $isRoot = $user?->isRoot() === true;

            if (! LocalRootLogin::enabled()) {
                if ($isRoot) {
                    LocalRootLogin::audit($request, $user, 'NEGADO', 'desligado_por_flag', ['metodo' => 'senha']);
                }

                return null;
            }

            if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
                if ($isRoot) {
                    LocalRootLogin::audit($request, $user, 'NEGADO', 'credenciais_invalidas', ['metodo' => 'senha']);
                }

                return null;
            }

            if (! $user->is_active || ! $isRoot) {
                if ($isRoot) {
                    LocalRootLogin::audit($request, $user, 'NEGADO', 'conta_inativa', ['metodo' => 'senha']);
                }

                return null;
            }

            return $user;
        });

        // Passkey é a outra porta local: mesma regra (só root ativo, e só
        // com o acesso local ligado).
        Passkeys::authorizeLoginUsing(function (Request $request, $user): bool {
            if (! $user instanceof User || ! $user->isRoot()) {
                return false;
            }

            if (! LocalRootLogin::enabled()) {
                LocalRootLogin::audit($request, $user, 'NEGADO', 'desligado_por_flag', ['metodo' => 'passkey']);

                return false;
            }

            if (! $user->is_active) {
                LocalRootLogin::audit($request, $user, 'NEGADO', 'conta_inativa', ['metodo' => 'passkey']);

                return false;
            }

            return true;
        });

        // Sucesso só conta quando a sessão abre de fato (depois do 2FA, se houver).
        Event::listen(Login::class, function (Login $event): void {
            $request = request();
            $route = $request->route()?->getName();

            if (! $event->user instanceof User || ! $event->user->isRoot()
                || ! in_array($route, ['login.store', 'two-factor.login.store', 'passkey.login'], true)) {
                return;
            }

            LocalRootLogin::audit($request, $event->user, 'PERMITIDO', 'sucesso', [
                'metodo' => match ($route) {
                    'two-factor.login.store' => 'senha_2fa',
                    'passkey.login' => 'passkey',
                    default => 'senha',
                },
            ]);
        });
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            // Sem as credenciais do Hub configuradas, a tela não oferece um
            // botão que só levaria a um erro — ambiente novo continua entrando
            // pelo acesso local até o SSO ser configurado.
            'hubEnabled' => LocalRootLogin::hubConfigured(),
            // Com o Hub configurado e a flag de contingência desligada, o
            // formulário local some da tela (o POST também é recusado).
            'localLoginEnabled' => LocalRootLogin::enabled(),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            // 5 por minuto por e-mail+IP; a 6ª é bloqueada (429). Bloqueio de
            // conta root é auditado como contingência.
            return Limit::perMinute(5)->by($throttleKey)->response(function (Request $request, array $headers): never {
                $user = User::query()->where('email', Str::lower((string) $request->input(Fortify::username())))->first();

                if ($user?->isRoot() === true) {
                    LocalRootLogin::audit($request, $user, 'NEGADO', 'bloqueado_por_tentativas', ['metodo' => 'senha']);
                }

                throw new ThrottleRequestsException('Too Many Attempts.', null, $headers);
            });
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}

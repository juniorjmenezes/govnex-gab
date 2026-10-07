<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureEntidadeModuleIsEnabled;
use App\Http\Middleware\EnsureGabineteIsActive;
use App\Http\Middleware\EnsureGabineteModuleIsEnabled;
use App\Http\Middleware\EnsureRoot;
use App\Http\Middleware\EnsureTenantUser;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectLegacyTenantRoute;
use App\Http\Middleware\ResolveEntidadeContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxySetting = $_SERVER['TRUSTED_PROXIES']
            ?? $_ENV['TRUSTED_PROXIES']
            ?? getenv('TRUSTED_PROXIES')
            ?: '';
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $trustedProxySetting),
        )));

        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_TRAEFIK,
            );
        }

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'user.active' => EnsureUserIsActive::class,
            'gabinete.active' => EnsureGabineteIsActive::class,
            'module' => EnsureGabineteModuleIsEnabled::class,
            'tenant.user' => EnsureTenantUser::class,
            'root' => EnsureRoot::class,
            'entidade.context' => ResolveEntidadeContext::class,
            'entidade.module' => EnsureEntidadeModuleIsEnabled::class,
            'tenant.legacy' => RedirectLegacyTenantRoute::class,
        ]);

        foreach ([
            EnsureUserIsActive::class,
            EnsureGabineteIsActive::class,
            ResolveEntidadeContext::class,
            EnsureTenantUser::class,
            RedirectLegacyTenantRoute::class,
        ] as $tenantMiddleware) {
            $middleware->prependToPriorityList(
                ThrottleRequests::class,
                $tenantMiddleware,
            );
        }

        $middleware->prependToPriorityList(
            EnsureTenantUser::class,
            RedirectLegacyTenantRoute::class,
        );

        $middleware->web(append: [
            AddSecurityHeaders::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            $maximum = (string) ini_get('post_max_size');
            $message = "O conteúdo enviado excede o limite de {$maximum} aceito pelo servidor.";

            // ValidatePostSize roda no grupo de middlewares globais, antes de
            // o StartSession do grupo "web" sequer começar — não existe
            // sessão disponível aqui, então "voltar com erro na sessão" não é
            // opção. Uma resposta JSON pura também quebra o protocolo do
            // Inertia (que exige de volta uma visita Inertia válida ou,
            // faltando isso, HTML puro para exibir no modal de erro
            // embutido). Só clientes de API "puros" (sem X-Inertia) recebem
            // JSON; todo o resto recebe HTML simples, que o Inertia já sabe
            // mostrar sozinho quando falta o cabeçalho X-Inertia.
            if ($request->header('X-Inertia') === null && $request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['arquivo' => [$message]],
                ], 413);
            }

            return response($message, 413);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (SymfonyResponse $response, Throwable $exception, Request $request) {
            if (app()->environment('testing') || $request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $status = $response->getStatusCode();

            // Com debug ligado, erro de servidor (500/503) cai no Whoops —
            // é o que ajuda a depurar o bug de verdade. 403/404/419/429 não
            // são bugs, são desfecho esperado da navegação: sempre mostram a
            // tela própria, inclusive em debug.
            if (config('app.debug') && in_array($status, [500, 503], true)) {
                return $response;
            }

            if (in_array($status, [403, 404, 419, 429, 500, 503], true)) {
                return Inertia::render('errors/error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();

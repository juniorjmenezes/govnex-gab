<?php

namespace App\Http\Middleware;

use App\Models\Gabinete;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGabineteIsActive
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user || $user->isRoot() || $request->route('entidade') !== null) {
            return $next($request);
        }

        if ($user->gabinete_id === null) {
            return $next($request);
        }

        $gabinete = Gabinete::withoutGlobalScopes()->find($user->gabinete_id);

        if (! $gabinete?->isActive()) {
            return redirect()->route('entidades.index')->withErrors([
                'gabinete' => 'O gabinete está temporariamente indisponível. Selecione outro contexto.',
            ]);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->gabinete_id !== null, 403, 'Esta área pertence ao painel de um gabinete.');

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRoot
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isRoot(), 403, 'Acesso restrito a administradores root.');

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Tenancy\OfficeContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantUser
{
    public function __construct(private readonly OfficeContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->context->officeIdFor($request->user()) !== null, 403, 'Esta área pertence ao painel de um gabinete.');

        return $next($request);
    }
}

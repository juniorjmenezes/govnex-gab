<?php

namespace App\Http\Middleware;

use App\Enums\EntidadeModule;
use App\Exceptions\EntidadeModuleDisabledException;
use App\Services\Modules\EntidadeModuleManager;
use App\Tenancy\EntidadeContext;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class EnsureEntidadeModuleIsEnabled
{
    public function __construct(
        private readonly EntidadeModuleManager $modules,
        private readonly EntidadeContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $code): Response
    {
        $module = EntidadeModule::tryFrom(strtoupper($code))
            ?? throw new LogicException("Módulo organizacional desconhecido no middleware: {$code}.");
        $entidade = $this->context->entidade();

        if (! $this->modules->isActive($entidade, $module)) {
            throw new EntidadeModuleDisabledException($module);
        }

        return $next($request);
    }
}

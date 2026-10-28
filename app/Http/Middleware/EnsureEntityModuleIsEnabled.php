<?php

namespace App\Http\Middleware;

use App\Enums\EntityModule;
use App\Exceptions\EntityModuleDisabledException;
use App\Services\Modules\EntityModuleManager;
use App\Tenancy\EntityContext;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class EnsureEntityModuleIsEnabled
{
    public function __construct(
        private readonly EntityModuleManager $modules,
        private readonly EntityContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $code): Response
    {
        $module = EntityModule::tryFrom(strtoupper($code))
            ?? throw new LogicException("Módulo organizacional desconhecido no middleware: {$code}.");
        $entidade = $this->context->entidade();

        if (! $this->modules->isActive($entidade, $module)) {
            throw new EntityModuleDisabledException($module);
        }

        return $next($request);
    }
}

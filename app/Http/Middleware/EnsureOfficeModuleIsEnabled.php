<?php

namespace App\Http\Middleware;

use App\Enums\OfficeModule;
use App\Exceptions\OfficeModuleDisabledException;
use App\Models\Office;
use App\Services\Modules\OfficeModuleManager;
use App\Tenancy\OfficeContext;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class EnsureOfficeModuleIsEnabled
{
    public function __construct(
        private readonly OfficeModuleManager $modules,
        private readonly OfficeContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $code): Response
    {
        $module = OfficeModule::tryFrom(strtoupper($code))
            ?? throw new LogicException("Módulo desconhecido no middleware: {$code}.");
        $routeOffice = $request->route('office');
        $office = $routeOffice instanceof Office
            ? $routeOffice
            : $this->context->officeFor($request->user());

        if (! $this->modules->isActive($office, $module)) {
            throw new OfficeModuleDisabledException($module);
        }

        return $next($request);
    }
}

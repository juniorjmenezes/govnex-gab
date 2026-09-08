<?php

namespace App\Http\Middleware;

use App\Enums\GabineteModule;
use App\Exceptions\GabineteModuleDisabledException;
use App\Models\Gabinete;
use App\Services\Modules\GabineteModuleManager;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class EnsureGabineteModuleIsEnabled
{
    public function __construct(private readonly GabineteModuleManager $modules) {}

    public function handle(Request $request, Closure $next, string $code): Response
    {
        $module = GabineteModule::tryFrom(strtoupper($code))
            ?? throw new LogicException("Módulo desconhecido no middleware: {$code}.");
        $routeOffice = $request->route('office');
        $office = $routeOffice instanceof Gabinete
            ? $routeOffice
            : $request->user()?->gabinete;

        if (! $this->modules->isActive($office, $module)) {
            throw new GabineteModuleDisabledException($module);
        }

        return $next($request);
    }
}

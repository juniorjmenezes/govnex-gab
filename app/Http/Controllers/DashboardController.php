<?php

namespace App\Http\Controllers;

use App\Enums\OfficeModule;
use App\Models\Demand;
use App\Services\Admin\PlatformMetricsService;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Modules\OfficeModuleManager;
use App\Tenancy\OfficeContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        DashboardMetricsService $dashboard,
        PlatformMetricsService $platform,
        OfficeModuleManager $modules,
        OfficeContext $gabinetes,
    ): Response|RedirectResponse {
        if ($request->user()?->isRoot() && ! $gabinetes->hasExplicitUnit()) {
            return Inertia::render('admin/dashboard', $platform->build());
        }

        $gabinete = $gabinetes->gabinete() ?? $gabinetes->officeFor($request->user());

        if ($gabinete === null) {
            return to_route('entidades.index');
        }

        $activeModules = $modules->activeFor($gabinete);

        if (in_array(OfficeModule::Demands->value, $activeModules, true)) {
            $this->authorize('viewAny', Demand::class);
        }

        $requestedPeriod = $request->integer('period', 90);
        $period = in_array($requestedPeriod, [30, 90, 180, 365], true) ? $requestedPeriod : 90;

        $timezone = $gabinete->timezone ?? 'America/Sao_Paulo';

        return Inertia::render('dashboard', $dashboard->build($period, $timezone, $activeModules, $gabinete));
    }
}

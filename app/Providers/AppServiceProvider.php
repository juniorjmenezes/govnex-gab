<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserEntidadeMembershipObserver;
use App\Services\Modules\EntidadeModuleManager;
use App\Services\Modules\GabineteModuleManager;
use App\Tenancy\EntidadeContext;
use App\Tenancy\GabineteContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(GabineteContext::class, fn (): GabineteContext => new GabineteContext);
        $this->app->scoped(EntidadeContext::class, fn (): EntidadeContext => new EntidadeContext);
        $this->app->scoped(GabineteModuleManager::class);
        $this->app->scoped(EntidadeModuleManager::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();
        User::observe(UserEntidadeMembershipObserver::class);

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        RateLimiter::for('geocoding', fn (Request $request): array => [
            Limit::perSecond(1)->by('geocoding-provider'),
            Limit::perMinute(20)->by((string) $request->user()->id),
        ]);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

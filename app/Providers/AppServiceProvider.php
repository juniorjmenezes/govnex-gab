<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserEntityMembershipObserver;
use App\Services\Hub\HubSocialiteProvider;
use App\Services\Modules\EntityModuleManager;
use App\Services\Modules\OfficeModuleManager;
use App\Tenancy\EntityContext;
use App\Tenancy\OfficeContext;
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
use Laravel\Socialite\Facades\Socialite;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(OfficeContext::class, fn (): OfficeContext => new OfficeContext);
        $this->app->scoped(EntityContext::class, fn (): EntityContext => new EntityContext);
        $this->app->scoped(OfficeModuleManager::class);
        $this->app->scoped(EntityModuleManager::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureHubSocialite();
        User::observe(UserEntityMembershipObserver::class);

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

    /**
     * Driver `hub` do Socialite — o SSO do Govnex Hub
     * (docs/INTEGRACAO_GOVNEX_HUB.md). Ver `HubSocialiteProvider` para por que
     * o provider é nosso e não de pacote.
     */
    protected function configureHubSocialite(): void
    {
        Socialite::extend('hub', fn ($app) => Socialite::buildProvider(
            HubSocialiteProvider::class,
            (array) config('services.hub'),
        ));
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

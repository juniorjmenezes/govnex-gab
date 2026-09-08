<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    private bool $followLegacyTenantRedirects = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        if (! $this->followLegacyTenantRedirects
            || $response->headers->get('X-GovnexGab-Legacy-Redirect') !== '1') {
            return $response;
        }

        $target = $response->headers->get('Location');
        if (! is_string($target) || ! str_contains($target, '/entidades/') || ! str_contains($target, '/gabinetes/')) {
            return $response;
        }

        if ($response->getStatusCode() === 307) {
            $redirectServer = $server;
            $redirectServer['HTTP_REFERER'] ??= (string) $uri;

            return parent::call($method, $target, $parameters, $cookies, $files, $redirectServer, $content);
        }

        return parent::call('GET', $target, [], $cookies, [], $server);
    }

    protected function withoutFollowingLegacyTenantRedirects(): static
    {
        $this->followLegacyTenantRedirects = false;

        return $this;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

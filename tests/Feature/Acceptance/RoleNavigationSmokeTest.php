<?php

use App\Models\Gabinete;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login from protected areas', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('demands.index'))->assertRedirect(route('login'));
    $this->get(route('admin.offices.index'))->assertRedirect(route('login'));
});

test('platform administrator reaches only the platform journey', function () {
    $admin = User::factory()->root()->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/dashboard'));

    $this->get(route('admin.offices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/offices/index'));

    $this->get(route('demands.index'))->assertForbidden();
    $this->get(route('citizens.index'))->assertForbidden();
    $this->get(route('appointments.index'))->assertForbidden();
    $this->get(route('attendances.index'))->assertForbidden();
    $this->get(route('voters.map'))->assertForbidden();
    $this->get(route('voters.prospecting-map'))->assertForbidden();
    $this->get(route('events.index'))->assertForbidden();
    $this->get(route('politics.index'))->assertForbidden();
});

test('tenant profiles reach the common operational journey', function (string $profile) {
    $office = Gabinete::factory()->create();
    $user = User::factory()->{$profile}()->forGabinete($office)->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $journey = [
        'dashboard',
        'demands.index',
        'demands.create',
        'demands.kanban',
        'citizens.index',
        'citizens.create',
        'categories.index',
        'neighborhoods.index',
        'appointments.index',
        'attendances.index',
        'attendances.create',
        'voters.map',
        'voters.prospecting-map',
        'events.index',
        'events.create',
        'politics.index',
        'office-settings.edit',
        'profile.edit',
    ];

    foreach ($journey as $route) {
        $this->get(route($route))->assertOk();
    }

    // Senha, 2FA e passkeys são o acesso local de emergência do root
    // (docs/INTEGRACAO_GOVNEX_HUB.md, decisões #2 e #6); os demais papéis
    // entram pelo Govnex Hub e não têm o que gerenciar aqui.
    $this->get(route('security.edit'))->assertRedirect(route('dashboard'));

    $this->get(route('admin.offices.index'))->assertForbidden();
})->with([
    'administrador' => 'administrator',
    'operador' => 'operator',
]);

test('auditor reaches the read-only journey but not the creation forms', function () {
    $office = Gabinete::factory()->create();
    $auditor = User::factory()->auditor()->forGabinete($office)->create();
    $this->actingAs($auditor);

    foreach ([
        'dashboard',
        'demands.index',
        'demands.kanban',
        'citizens.index',
        'categories.index',
        'neighborhoods.index',
        'appointments.index',
        'attendances.index',
        'events.index',
        'office-settings.edit',
        'profile.edit',
    ] as $route) {
        $this->get(route($route))->assertOk();
    }

    foreach (['demands.create', 'citizens.create', 'attendances.create', 'events.create'] as $route) {
        $this->get(route($route))->assertForbidden();
    }
});

test('management journeys respect each tenant profile', function () {
    $office = Gabinete::factory()->create();
    $administrator = User::factory()->administrator()->forGabinete($office)->create();
    $operator = User::factory()->operator()->forGabinete($office)->create();
    $auditor = User::factory()->auditor()->forGabinete($office)->create();

    $this->actingAs($administrator)->get(route('team.index'))->assertOk();
    $this->get(route('reports.index'))->assertOk();

    foreach ([$operator, $auditor] as $user) {
        $this->actingAs($user)->get(route('team.index'))->assertForbidden();
        $this->get(route('reports.index'))->assertForbidden();
    }
});

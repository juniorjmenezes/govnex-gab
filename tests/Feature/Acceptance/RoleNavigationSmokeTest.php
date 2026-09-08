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
        'security.edit',
    ];

    foreach ($journey as $route) {
        $this->get(route($route))->assertOk();
    }

    $this->get(route('admin.offices.index'))->assertForbidden();
})->with([
    'vereador' => 'councilor',
    'chefe de gabinete' => 'chiefOfStaff',
    'assessor' => 'advisor',
]);

test('management journeys respect each tenant profile', function () {
    $office = Gabinete::factory()->create();
    $councilor = User::factory()->councilor()->forGabinete($office)->create();
    $chief = User::factory()->chiefOfStaff()->forGabinete($office)->create();
    $advisor = User::factory()->advisor()->forGabinete($office)->create();

    foreach ([$councilor, $chief] as $manager) {
        $this->actingAs($manager)->get(route('team.index'))->assertOk();
        $this->get(route('reports.index'))->assertOk();
    }

    $this->actingAs($advisor)->get(route('team.index'))->assertForbidden();
    $this->get(route('reports.index'))->assertForbidden();
});

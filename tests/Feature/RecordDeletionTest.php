<?php

use App\Models\Appointment;
use App\Models\Category;
use App\Models\Citizen;
use App\Models\Demand;
use App\Models\Neighborhood;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('office managers can soft delete operational records', function () {
    $office = Office::factory()->create();
    $manager = User::factory()->administrator()->forGabinete($office)->create();
    $category = Category::factory()->forGabinete($office)->create();
    $neighborhood = Neighborhood::factory()->forGabinete($office)->create();
    $citizen = Citizen::factory()->forGabinete($office)->create();
    $demand = Demand::factory()->forGabinete($office)->create();
    $appointment = Appointment::factory()->forGabinete($office)->create();
    $member = User::factory()->operator()->forGabinete($office)->create();

    $this->actingAs($manager)
        ->delete(route('categories.destroy', $category))
        ->assertRedirect(route('categories.index'))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Categoria excluída.',
        ]);
    $this->assertSoftDeleted($category);

    $this->delete(route('neighborhoods.destroy', $neighborhood))
        ->assertRedirect(route('context.neighborhoods.index', [
            'entidade' => $office->entidade,
            'gabinete' => $office,
        ]));
    $this->assertSoftDeleted($neighborhood);

    $this->delete(route('citizens.destroy', $citizen))
        ->assertRedirect(route('citizens.index'));
    $this->assertSoftDeleted($citizen);

    $this->delete(route('demands.destroy', $demand))
        ->assertRedirect(route('demands.index'));
    $this->assertSoftDeleted($demand);

    $this->delete(route('appointments.destroy', $appointment))
        ->assertRedirect(route('appointments.index'));
    $this->assertSoftDeleted($appointment);

    // Vínculos passaram a ser geridos no Govnex Hub: a remoção local de
    // membro da equipe foi desligada, mesmo para quem gerencia o gabinete.
    $this->delete(route('team.destroy', $member))
        ->assertForbidden();
    $this->assertNotSoftDeleted($member);
    $this->assertDatabaseHas('gabinete_membros', [
        'gabinete_id' => $office->id,
        'usuario_id' => $member->id,
        'ativo' => true,
    ]);
});

test('historical demand relations remain available after linked records are deleted', function () {
    $office = Office::factory()->create();
    $manager = User::factory()->administrator()->forGabinete($office)->create();
    $category = Category::factory()->forGabinete($office)->create();
    $neighborhood = Neighborhood::factory()->forGabinete($office)->create();
    $citizen = Citizen::factory()->forGabinete($office)->create(['bairro_id' => $neighborhood->id]);
    $demand = Demand::factory()->forGabinete($office)->create([
        'categoria_id' => $category->id,
        'bairro_id' => $neighborhood->id,
        'cidadao_id' => $citizen->id,
        'criado_por_id' => $manager->id,
    ]);

    $category->delete();
    $neighborhood->delete();
    $citizen->delete();

    $demand->refresh()->load(['categoria', 'bairro', 'cidadao']);

    expect($demand->categoria?->is($category))->toBeTrue()
        ->and($demand->bairro?->is($neighborhood))->toBeTrue()
        ->and($demand->cidadao?->is($citizen))->toBeTrue();
});

test('advisors cannot delete records and users cannot delete themselves', function () {
    $office = Office::factory()->create();
    $advisor = User::factory()->operator()->forGabinete($office)->create();
    $category = Category::factory()->forGabinete($office)->create();

    $this->actingAs($advisor)
        ->delete(route('categories.destroy', $category))
        ->assertForbidden();

    $manager = User::factory()->administrator()->forGabinete($office)->create();

    $this->actingAs($manager)
        ->delete(route('team.destroy', $manager))
        ->assertForbidden();

    $this->assertNotSoftDeleted($category);
    $this->assertNotSoftDeleted($manager);
});

test('records from another office cannot be deleted', function () {
    $office = Office::factory()->create();
    $otherOffice = Office::factory()->create();
    $manager = User::factory()->administrator()->forGabinete($office)->create();
    $foreignCategory = Category::factory()->forGabinete($otherOffice)->create();

    $this->actingAs($manager)
        ->delete(route('categories.destroy', $foreignCategory))
        ->assertNotFound();

    $this->assertNotSoftDeleted($foreignCategory);
});

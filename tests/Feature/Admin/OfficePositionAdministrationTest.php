<?php

use App\Enums\OfficePosition;
use App\Enums\OfficeType;
use App\Enums\UserRole;
use App\Models\AccessContextEvent;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

function adminOffice(OfficeType $type = OfficeType::CouncilorOffice): Office
{
    return Office::factory()->create(['tipo_gabinete' => $type]);
}

function linkedWithoutPosition(Office $office, UserRole $role = UserRole::Operator): User
{
    return User::factory()->forGabinete($office)->withoutPosition()->create(['role' => $role]);
}

function positionRoute(Office $office, User $member): string
{
    return route('admin.offices.positions.update', ['office' => $office->id, 'member' => $member->id]);
}

it('deixa o root conceder, alterar e remover cargos com motivo e grava a auditoria', function () {
    $root = User::factory()->root()->create();
    $office = adminOffice();
    $member = linkedWithoutPosition($office);

    $this->actingAs($root)
        ->put(positionRoute($office, $member), ['cargo' => 'VEREADOR', 'motivo' => 'Partida do gabinete'])
        ->assertRedirect();
    expect($member->gabinetePosition($office->id))->toBe(OfficePosition::Councilor);

    $event = AccessContextEvent::query()->where('evento', 'CARGO_CONCEDIDO_POR_OPERADOR')->latest('id')->first();
    expect($event->usuario_id)->toBe($root->id)
        ->and($event->gabinete_id)->toBe($office->id)
        ->and($event->administrador_plataforma)->toBeTrue()
        ->and($event->contexto)->toBe([
            'membro_id' => $member->id,
            'de' => null,
            'para' => 'VEREADOR',
            'origem' => 'tela_administracao',
            'ator' => 'root',
            'motivo' => 'Partida do gabinete',
        ]);

    $this->actingAs($root)
        ->put(positionRoute($office, $member), ['cargo' => 'CHEFE_GABINETE', 'motivo' => 'Troca de função'])
        ->assertRedirect();
    expect($member->gabinetePosition($office->id))->toBe(OfficePosition::ChiefOfStaff);

    $this->actingAs($root)
        ->put(positionRoute($office, $member), ['cargo' => null, 'motivo' => 'Saiu da equipe'])
        ->assertRedirect();
    expect($member->gabinetePosition($office->id))->toBeNull()
        ->and(AccessContextEvent::query()->where('evento', 'CARGO_CONCEDIDO_POR_OPERADOR')->count())->toBe(3)
        ->and(AccessContextEvent::query()->latest('id')->first()->contexto['para'])->toBeNull();
});

it('é idempotente quando o cargo não muda', function () {
    $root = User::factory()->root()->create();
    $office = adminOffice();
    $member = linkedWithoutPosition($office);
    $payload = ['cargo' => 'ASSESSOR', 'motivo' => 'Partida do gabinete'];

    $this->actingAs($root)->put(positionRoute($office, $member), $payload)->assertRedirect();
    $this->actingAs($root)->put(positionRoute($office, $member), $payload)->assertRedirect();

    expect(AccessContextEvent::query()->where('evento', 'CARGO_CONCEDIDO_POR_OPERADOR')->count())->toBe(1);
});

it('recusa quem não é root, inclusive vereador, chefe e administrador do Hub', function () {
    $office = adminOffice();
    $member = linkedWithoutPosition($office);
    $councilor = User::factory()->forGabinete($office)->withPosition(OfficePosition::Councilor)->create();
    $chief = User::factory()->forGabinete($office)->withPosition(OfficePosition::ChiefOfStaff)->create();
    $hubAdmin = User::factory()->forGabinete($office)->withoutPosition()->create(['role' => UserRole::Administrator]);

    foreach ([$councilor, $chief, $hubAdmin] as $user) {
        $this->actingAs($user)
            ->get(route('admin.offices.positions.index', $office->id))
            ->assertForbidden();
        $this->actingAs($user)
            ->put(positionRoute($office, $member), ['cargo' => 'VEREADOR', 'motivo' => 'Tentativa indevida'])
            ->assertForbidden();
    }

    expect($member->gabinetePosition($office->id))->toBeNull()
        ->and(AccessContextEvent::query()->where('evento', 'CARGO_CONCEDIDO_POR_OPERADOR')->exists())->toBeFalse();
});

it('exige motivo e cargo permitido ao tipo do gabinete', function () {
    $root = User::factory()->root()->create();
    $office = adminOffice();
    $member = linkedWithoutPosition($office);

    $this->actingAs($root)
        ->putJson(positionRoute($office, $member), ['cargo' => 'VEREADOR'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('motivo');

    $this->actingAs($root)
        ->putJson(positionRoute($office, $member), ['cargo' => 'SECRETARIO', 'motivo' => 'Cargo errado'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cargo');

    $mayor = adminOffice(OfficeType::MayorOffice);
    $mayorMember = linkedWithoutPosition($mayor);
    $this->actingAs($root)
        ->putJson(positionRoute($mayor, $mayorMember), ['cargo' => 'VEREADOR', 'motivo' => 'Cargo errado'])
        ->assertStatus(422);
    $this->actingAs($root)
        ->put(positionRoute($mayor, $mayorMember), ['cargo' => 'AUDITOR', 'motivo' => 'Somente leitura'])
        ->assertRedirect();

    expect($member->gabinetePosition($office->id))->toBeNull()
        ->and($mayorMember->gabinetePosition($mayor->id))->toBe(OfficePosition::Auditor);
});

it('recusa integrante de outro gabinete', function () {
    $root = User::factory()->root()->create();
    $office = adminOffice();
    $stranger = linkedWithoutPosition(adminOffice());

    $this->actingAs($root)
        ->put(positionRoute($office, $stranger), ['cargo' => 'VEREADOR', 'motivo' => 'Tentativa cruzada'])
        ->assertNotFound();

    expect(OfficeMember::query()->whereNotNull('cargo')->where('usuario_id', $stranger->id)->exists())->toBeFalse();
});

it('lista os integrantes com Sem cargo e os cargos do tipo', function () {
    $root = User::factory()->root()->create();
    $office = adminOffice();
    $member = linkedWithoutPosition($office);
    $advisor = User::factory()->forGabinete($office)->withPosition(OfficePosition::Advisor)->create();

    $this->actingAs($root)
        ->get(route('admin.offices.positions.index', $office->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/offices/positions')
            ->where('positionOptions', fn ($options) => collect($options)->pluck('value')->all() === ['VEREADOR', 'CHEFE_GABINETE', 'ASSESSOR', 'AUDITOR'])
            ->where('members', fn ($members) => collect($members)->firstWhere('id', $member->id)['position'] === null
                && collect($members)->firstWhere('id', $advisor->id)['position'] === 'ASSESSOR'
                && collect($members)->firstWhere('id', $member->id)['link_active'] === true));
});

it('sinaliza na lista de gabinetes os que não têm nenhum cargo', function () {
    $root = User::factory()->root()->create();
    $withoutPositions = adminOffice();
    linkedWithoutPosition($withoutPositions);
    $withPositions = adminOffice();
    User::factory()->forGabinete($withPositions)->withPosition(OfficePosition::Councilor)->create();

    $this->actingAs($root)
        ->get(route('admin.offices.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('offices.data', fn ($offices) => collect($offices)->firstWhere('id', $withoutPositions->id)['has_positions'] === false
                && collect($offices)->firstWhere('id', $withPositions->id)['has_positions'] === true));
});

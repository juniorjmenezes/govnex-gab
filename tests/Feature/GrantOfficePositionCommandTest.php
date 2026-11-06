<?php

use App\Enums\OfficePosition;
use App\Enums\OfficeType;
use App\Enums\UserRole;
use App\Models\AccessContextEvent;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;

/** Gabinete de vereador ligado ao Hub e uma pessoa (operador) com vínculo ativo nele. */
function grantScenario(): array
{
    $office = Office::factory()->create([
        'tipo_gabinete' => OfficeType::CouncilorOffice,
        'hub_unidade_id' => '12',
    ]);
    $user = User::factory()->forGabinete($office)->withoutPosition()->create([
        'role' => UserRole::Operator,
        'hub_user_id' => '42',
    ]);

    return [$office, $user];
}

it('concede o cargo e grava a auditoria com ator sistema e motivo', function () {
    [$office, $user] = grantScenario();

    $this->artisan('gep:cargo-conceder', ['hub_user_id' => '42', 'hub_unidade_id' => '12', 'cargo' => 'chefe_gabinete', '--motivo' => 'Partida do gabinete'])
        ->expectsOutputToContain('Cargo Chefe de gabinete concedido')
        ->assertSuccessful();

    expect($user->gabinetePosition($office->id))->toBe(OfficePosition::ChiefOfStaff);

    $event = AccessContextEvent::query()->where('evento', 'CARGO_CONCEDIDO_POR_OPERADOR')->sole();
    expect($event->usuario_id)->toBeNull()
        ->and($event->gabinete_id)->toBe($office->id)
        ->and($event->entidade_id)->toBe($office->entidade_id)
        ->and($event->contexto)->toBe([
            'membro_id' => $user->id,
            'de' => null,
            'para' => 'CHEFE_GABINETE',
            'origem' => 'gep:cargo-conceder',
            'ator' => 'sistema',
            'motivo' => 'Partida do gabinete',
        ]);
});

it('é idempotente: repetir não altera nem audita de novo', function () {
    [$office, $user] = grantScenario();
    $args = ['hub_user_id' => '42', 'hub_unidade_id' => '12', 'cargo' => 'VEREADOR', '--motivo' => 'Partida'];

    $this->artisan('gep:cargo-conceder', $args)->assertSuccessful();
    $this->artisan('gep:cargo-conceder', $args)
        ->expectsOutputToContain('Nada alterado')
        ->assertSuccessful();

    expect(AccessContextEvent::query()->where('evento', 'CARGO_CONCEDIDO_POR_OPERADOR')->count())->toBe(1)
        ->and($user->gabinetePosition($office->id))->toBe(OfficePosition::Councilor);
});

it('exige motivo', function () {
    [$office, $user] = grantScenario();

    $this->artisan('gep:cargo-conceder', ['hub_user_id' => '42', 'hub_unidade_id' => '12', 'cargo' => 'VEREADOR'])
        ->expectsOutputToContain('--motivo')
        ->assertFailed();

    expect($user->gabinetePosition($office->id))->toBeNull();
});

it('recusa pessoa inexistente ou inativa', function () {
    [$office, $user] = grantScenario();

    $this->artisan('gep:cargo-conceder', ['hub_user_id' => '999', 'hub_unidade_id' => '12', 'cargo' => 'VEREADOR', '--motivo' => 'x'])
        ->expectsOutputToContain('Nenhuma pessoa')
        ->assertFailed();

    $user->forceFill(['is_active' => false])->saveQuietly();
    $this->artisan('gep:cargo-conceder', ['hub_user_id' => '42', 'hub_unidade_id' => '12', 'cargo' => 'VEREADOR', '--motivo' => 'x'])
        ->expectsOutputToContain('inativa')
        ->assertFailed();

    expect(OfficeMember::query()->whereNotNull('cargo')->exists())->toBeFalse();
});

it('recusa gabinete inexistente e vínculo inexistente ou inativo', function () {
    [$office, $user] = grantScenario();

    $this->artisan('gep:cargo-conceder', ['hub_user_id' => '42', 'hub_unidade_id' => '777', 'cargo' => 'VEREADOR', '--motivo' => 'x'])
        ->expectsOutputToContain('Nenhum gabinete')
        ->assertFailed();

    OfficeMember::query()->where('usuario_id', $user->id)->update(['ativo' => false, 'desativado_em' => now()]);
    $this->artisan('gep:cargo-conceder', ['hub_user_id' => '42', 'hub_unidade_id' => '12', 'cargo' => 'VEREADOR', '--motivo' => 'x'])
        ->expectsOutputToContain('vínculo ativo')
        ->assertFailed();

    $other = Office::factory()->create(['tipo_gabinete' => OfficeType::CouncilorOffice, 'hub_unidade_id' => '13']);
    $this->artisan('gep:cargo-conceder', ['hub_user_id' => '42', 'hub_unidade_id' => '13', 'cargo' => 'VEREADOR', '--motivo' => 'x'])
        ->expectsOutputToContain('vínculo ativo')
        ->assertFailed();

    expect(OfficeMember::query()->whereNotNull('cargo')->exists())->toBeFalse()
        ->and($other->id)->not->toBe($office->id);
});

it('recusa cargo inválido ou não permitido ao tipo do gabinete', function () {
    [$office, $user] = grantScenario();

    $this->artisan('gep:cargo-conceder', ['hub_user_id' => '42', 'hub_unidade_id' => '12', 'cargo' => 'PREFEITO', '--motivo' => 'x'])
        ->expectsOutputToContain('Cargo inválido')
        ->assertFailed();

    $this->artisan('gep:cargo-conceder', ['hub_user_id' => '42', 'hub_unidade_id' => '12', 'cargo' => 'SECRETARIO', '--motivo' => 'x'])
        ->expectsOutputToContain('não é permitido')
        ->assertFailed();

    expect($user->gabinetePosition($office->id))->toBeNull();
});

it('recusa root', function () {
    grantScenario();
    User::factory()->root()->create(['hub_user_id' => '1']);

    $this->artisan('gep:cargo-conceder', ['hub_user_id' => '1', 'hub_unidade_id' => '12', 'cargo' => 'VEREADOR', '--motivo' => 'x'])
        ->expectsOutputToContain('Root')
        ->assertFailed();

    expect(AccessContextEvent::query()->where('evento', 'CARGO_CONCEDIDO_POR_OPERADOR')->exists())->toBeFalse();
});

it('depois da partida, quem recebeu o cargo atribui os demais pela Equipe', function () {
    [$office, $user] = grantScenario();
    $teammate = User::factory()->forGabinete($office)->withoutPosition()->create(['role' => UserRole::Operator]);

    $this->artisan('gep:cargo-conceder', ['hub_user_id' => '42', 'hub_unidade_id' => '12', 'cargo' => 'VEREADOR', '--motivo' => 'Partida'])
        ->assertSuccessful();

    $this->withoutVite()
        ->actingAs($user)
        ->put(route('context.team.position.update', [
            'entidade' => $office->entidade->slug,
            'gabinete' => $office->slug,
            'usuario' => $teammate->id,
        ]), ['cargo' => 'ASSESSOR'])
        ->assertRedirect();

    expect($teammate->gabinetePosition($office->id))->toBe(OfficePosition::Advisor);
});

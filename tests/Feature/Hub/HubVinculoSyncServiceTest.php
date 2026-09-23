<?php

use App\Enums\AccessRole;
use App\Enums\EntidadeType;
use App\Enums\UserRole;
use App\Models\Entidade;
use App\Models\EntidadeMembro;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use App\Services\Hub\HubVinculoSyncService;

function hubSync(): HubVinculoSyncService
{
    return app(HubVinculoSyncService::class);
}

/** Uma entidade e um gabinete já espelhados do Hub. */
function hubEstrutura(string $entidadeId = '3', string $unidadeId = '12'): array
{
    $entidade = Entidade::factory()->create(['hub_entidade_id' => $entidadeId]);
    $gabinete = Gabinete::factory()->create([
        'entidade_id' => $entidade->id,
        'hub_unidade_id' => $unidadeId,
    ]);

    return [$entidade, $gabinete];
}

it('cria vínculo de entidade e gabinete a partir do retrato do Hub', function () {
    [$entidade, $gabinete] = hubEstrutura();
    $user = User::factory()->create(['gabinete_id' => null, 'role' => UserRole::Operator]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '3',
        'unidade_id' => '12',
        'papel' => 'operador',
        'ativo' => true,
    ]]);

    expect($user->fresh()->gabineteRole($gabinete->id))->toBe(AccessRole::Operator)
        ->and($user->fresh()->entidadeRole($entidade->id))->toBe(AccessRole::Operator)
        ->and($user->fresh()->gabinete_id)->toBe($gabinete->id)
        ->and($user->fresh()->role)->toBe(UserRole::Operator);
});

it('mapeia administrador de gabinete independente para administrador da entidade', function () {
    [$entidade, $gabinete] = hubEstrutura();
    $entidade->forceFill(['tipo' => EntidadeType::IndependentOffice])->save();
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'administrador', 'ativo' => true,
    ]]);

    expect($user->fresh()->entidadeRole($entidade->id))->toBe(AccessRole::Administrator)
        ->and($user->fresh()->gabineteRole($gabinete->id))->toBe(AccessRole::Administrator)
        ->and($user->fresh()->role)->toBe(UserRole::Administrator);
});

it('desativa, sem apagar, o vínculo que não veio mais no retrato', function () {
    [$entidade, $gabinete] = hubEstrutura();
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true,
    ]]);
    hubSync()->aplicar($user, []);

    $vinculo = GabineteMembro::query()
        ->where('gabinete_id', $gabinete->id)
        ->where('usuario_id', $user->id)
        ->first();

    expect($vinculo)->not->toBeNull()
        ->and($vinculo->ativo)->toBeFalse()
        ->and($vinculo->desativado_em)->not->toBeNull()
        ->and(EntidadeMembro::query()->where('entidade_id', $entidade->id)->where('usuario_id', $user->id)->value('ativo'))->toBeFalsy()
        ->and($user->fresh()->gabinete_id)->toBeNull();
});

it('preserva o ingressou_em original ao reativar um vínculo', function () {
    [, $gabinete] = hubEstrutura();
    $user = User::factory()->create(['gabinete_id' => null]);
    $vinculo = ['entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true];

    hubSync()->aplicar($user, [$vinculo]);
    $entrada = GabineteMembro::query()->where('usuario_id', $user->id)->value('ingressou_em');

    hubSync()->aplicar($user, []);
    hubSync()->aplicar($user, [$vinculo]);

    $reativado = GabineteMembro::query()->where('usuario_id', $user->id)->first();

    expect($reativado->ativo)->toBeTrue()
        ->and($reativado->desativado_em)->toBeNull()
        ->and($reativado->ingressou_em->toIso8601String())->toBe($entrada->toIso8601String());
});

it('elege como principal o vínculo de gabinete ativo mais antigo', function () {
    [$entidade] = hubEstrutura('3', '12');
    $primeiro = Gabinete::query()->where('hub_unidade_id', '12')->first();
    $segundo = Gabinete::factory()->create([
        'entidade_id' => $entidade->id,
        'hub_unidade_id' => '13',
    ]);
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [
        ['entidade_id' => '3', 'unidade_id' => '13', 'papel' => 'administrador', 'ativo' => true],
        ['entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true],
    ]);

    // Os dois entram no mesmo instante; o desempate é pela ordem de criação da
    // linha, e o primeiro escrito foi o da unidade 13.
    expect($user->fresh()->gabinete_id)->toBe($segundo->id)
        ->and($user->fresh()->gabineteRole($primeiro->id))->toBe(AccessRole::Operator);
});

it('ignora vínculo cuja entidade ainda não foi espelhada no GAB', function () {
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '999', 'unidade_id' => null, 'papel' => 'administrador', 'ativo' => true,
    ]]);

    expect(EntidadeMembro::query()->where('usuario_id', $user->id)->count())->toBe(0);
});

it('aplicarVinculo não mexe nos demais vínculos da pessoa', function () {
    [$entidade] = hubEstrutura('3', '12');
    Gabinete::factory()->create(['entidade_id' => $entidade->id, 'hub_unidade_id' => '13']);
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [
        ['entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true],
        ['entidade_id' => '3', 'unidade_id' => '13', 'papel' => 'operador', 'ativo' => true],
    ]);

    hubSync()->aplicarVinculo($user, [
        'entidade_id' => '3', 'unidade_id' => '13', 'papel' => 'operador', 'ativo' => false,
    ]);

    expect(GabineteMembro::query()->where('usuario_id', $user->id)->where('ativo', true)->count())->toBe(1);
});

it('não reprojeta contexto legado em conta root', function () {
    hubEstrutura();
    $user = User::factory()->root()->create();

    hubSync()->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'administrador', 'ativo' => true,
    ]]);

    expect($user->fresh()->role)->toBe(UserRole::Root)
        ->and($user->fresh()->gabinete_id)->toBeNull();
});

it('não derruba os vínculos locais quando o Hub devolve só vínculo não mapeável', function () {
    [$entidade, $gabinete] = hubEstrutura();
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true,
    ]]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '999', 'unidade_id' => '888', 'papel' => 'operador', 'ativo' => true,
    ]]);

    expect(GabineteMembro::query()->where('gabinete_id', $gabinete->id)->where('usuario_id', $user->id)->value('ativo'))->toBeTruthy()
        ->and(EntidadeMembro::query()->where('entidade_id', $entidade->id)->where('usuario_id', $user->id)->value('ativo'))->toBeTruthy()
        ->and($user->fresh()->gabinete_id)->toBe($gabinete->id);
});

it('aplica o vínculo mapeável e preserva o resto quando há vínculo não mapeável na mesma lista', function () {
    [, $gabinete] = hubEstrutura('3', '12');
    [, $outro] = hubEstrutura('4', '20');
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '4', 'unidade_id' => '20', 'papel' => 'operador', 'ativo' => true,
    ]]);

    hubSync()->aplicar($user, [
        ['entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true],
        ['entidade_id' => '999', 'unidade_id' => '888', 'papel' => 'operador', 'ativo' => true],
    ]);

    expect(GabineteMembro::query()->where('gabinete_id', $gabinete->id)->where('usuario_id', $user->id)->value('ativo'))->toBeTruthy()
        ->and(GabineteMembro::query()->where('gabinete_id', $outro->id)->where('usuario_id', $user->id)->value('ativo'))->toBeTruthy();
});

it('em Câmara, administrador do gabinete é operador da entidade', function () {
    [$entidade, $gabinete] = hubEstrutura();
    $entidade->forceFill(['tipo' => EntidadeType::CityCouncil])->save();
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'administrador', 'ativo' => true,
    ]]);

    expect($user->fresh()->gabineteRole($gabinete->id))->toBe(AccessRole::Administrator)
        ->and($user->fresh()->entidadeRole($entidade->id))->toBe(AccessRole::Operator)
        ->and($user->fresh()->role)->toBe(UserRole::Administrator);
});

it('aplica o papel auditor no gabinete e na entidade', function () {
    [$entidade, $gabinete] = hubEstrutura();
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'auditor', 'ativo' => true,
    ]]);

    expect($user->fresh()->gabineteRole($gabinete->id))->toBe(AccessRole::Auditor)
        ->and($user->fresh()->entidadeRole($entidade->id))->toBe(AccessRole::Auditor)
        ->and($user->fresh()->role)->toBe(UserRole::Auditor);
});

it('papel desconhecido do Hub vira auditor', function () {
    [, $gabinete] = hubEstrutura();
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'vereador', 'ativo' => true,
    ]]);

    expect($user->fresh()->gabineteRole($gabinete->id))->toBe(AccessRole::Auditor)
        ->and($user->fresh()->role)->toBe(UserRole::Auditor);
});

it('ignora vínculo com papel root sem travar a desativação dos ausentes', function () {
    [, $gabinete] = hubEstrutura('3', '12');
    [, $outro] = hubEstrutura('4', '20');
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '4', 'unidade_id' => '20', 'papel' => 'operador', 'ativo' => true,
    ]]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'root', 'ativo' => true,
    ]]);

    expect(GabineteMembro::query()->where('gabinete_id', $gabinete->id)->where('usuario_id', $user->id)->exists())->toBeFalse()
        ->and(GabineteMembro::query()->where('gabinete_id', $outro->id)->where('usuario_id', $user->id)->value('ativo'))->toBeFalsy()
        ->and($user->fresh()->role)->not->toBe(UserRole::Root);

    hubSync()->aplicarVinculo($user, [
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'root', 'ativo' => true,
    ]);

    expect(GabineteMembro::query()->where('gabinete_id', $gabinete->id)->where('usuario_id', $user->id)->exists())->toBeFalse();
});

it('preserva o administrador ao receber depois um vínculo de operador em outra unidade', function () {
    [$entidade, $gabinete] = hubEstrutura('3', '12');
    $segundo = Gabinete::factory()->create(['entidade_id' => $entidade->id, 'hub_unidade_id' => '13']);
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'administrador', 'ativo' => true,
    ]]);

    $this->travel(1)->minutes();

    hubSync()->aplicarVinculo($user, [
        'entidade_id' => '3', 'unidade_id' => '13', 'papel' => 'operador', 'ativo' => true,
    ]);
    hubSync()->aplicar($user, [
        ['entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'administrador', 'ativo' => true],
        ['entidade_id' => '3', 'unidade_id' => '13', 'papel' => 'operador', 'ativo' => true],
    ]);

    expect($user->fresh()->gabinete_id)->toBe($gabinete->id)
        ->and($user->fresh()->role)->toBe(UserRole::Administrator)
        ->and($user->fresh()->gabineteRole($gabinete->id))->toBe(AccessRole::Administrator)
        ->and($user->fresh()->gabineteRole($segundo->id))->toBe(AccessRole::Operator);
});

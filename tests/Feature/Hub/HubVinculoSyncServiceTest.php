<?php

use App\Enums\EntityType;
use App\Enums\OfficePosition;
use App\Enums\UserRole;
use App\Models\Entity;
use App\Models\EntityMember;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;
use App\Services\Hub\HubVinculoSyncService;
use Illuminate\Support\Facades\Http;

function vinculadoAoGabinete(User $user, int $gabineteId): bool
{
    return OfficeMember::query()->where('usuario_id', $user->id)->where('gabinete_id', $gabineteId)->where('ativo', true)->exists();
}

function vinculadoAEntidade(User $user, int $entidadeId): bool
{
    return EntityMember::query()->where('usuario_id', $user->id)->where('entidade_id', $entidadeId)->where('ativo', true)->exists();
}

function hubSync(): HubVinculoSyncService
{
    return app(HubVinculoSyncService::class);
}

/** Uma entidade e um gabinete já espelhados do Hub. */
function hubEstrutura(string $entidadeId = '3', string $unidadeId = '12'): array
{
    $entidade = Entity::factory()->create(['hub_entidade_id' => $entidadeId]);
    $gabinete = Office::factory()->create([
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

    expect(vinculadoAoGabinete($user, $gabinete->id))->toBeTrue()
        ->and(vinculadoAEntidade($user, $entidade->id))->toBeTrue()
        ->and($user->fresh()->gabinete_id)->toBe($gabinete->id)
        ->and($user->fresh()->role)->toBe(UserRole::Operator);
});

it('desativa, sem apagar, o vínculo que não veio mais no retrato', function () {
    [$entidade, $gabinete] = hubEstrutura();
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true,
    ]]);
    hubSync()->aplicar($user, []);

    $vinculo = OfficeMember::query()
        ->where('gabinete_id', $gabinete->id)
        ->where('usuario_id', $user->id)
        ->first();

    expect($vinculo)->not->toBeNull()
        ->and($vinculo->ativo)->toBeFalse()
        ->and($vinculo->desativado_em)->not->toBeNull()
        ->and(EntityMember::query()->where('entidade_id', $entidade->id)->where('usuario_id', $user->id)->value('ativo'))->toBeFalsy()
        ->and($user->fresh()->gabinete_id)->toBeNull();
});

it('preserva o ingressou_em original ao reativar um vínculo', function () {
    [, $gabinete] = hubEstrutura();
    $user = User::factory()->create(['gabinete_id' => null]);
    $vinculo = ['entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true];

    hubSync()->aplicar($user, [$vinculo]);
    $entrada = OfficeMember::query()->where('usuario_id', $user->id)->value('ingressou_em');

    hubSync()->aplicar($user, []);
    hubSync()->aplicar($user, [$vinculo]);

    $reativado = OfficeMember::query()->where('usuario_id', $user->id)->first();

    expect($reativado->ativo)->toBeTrue()
        ->and($reativado->desativado_em)->toBeNull()
        ->and($reativado->ingressou_em->toIso8601String())->toBe($entrada->toIso8601String());
});

it('elege como principal o vínculo de gabinete ativo mais antigo', function () {
    [$entidade] = hubEstrutura('3', '12');
    $primeiro = Office::query()->where('hub_unidade_id', '12')->first();
    $segundo = Office::factory()->create([
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
        ->and(vinculadoAoGabinete($user, $primeiro->id))->toBeTrue();
});

it('ignora vínculo cuja entidade ainda não foi espelhada no GEP', function () {
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '999', 'unidade_id' => null, 'papel' => 'administrador', 'ativo' => true,
    ]]);

    expect(EntityMember::query()->where('usuario_id', $user->id)->count())->toBe(0);
});

it('aplicarVinculo não mexe nos demais vínculos da pessoa', function () {
    [$entidade] = hubEstrutura('3', '12');
    Office::factory()->create(['entidade_id' => $entidade->id, 'hub_unidade_id' => '13']);
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [
        ['entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true],
        ['entidade_id' => '3', 'unidade_id' => '13', 'papel' => 'operador', 'ativo' => true],
    ]);

    hubSync()->aplicarVinculo($user, [
        'entidade_id' => '3', 'unidade_id' => '13', 'papel' => 'operador', 'ativo' => false,
    ]);

    expect(OfficeMember::query()->where('usuario_id', $user->id)->where('ativo', true)->count())->toBe(1);
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

    expect(OfficeMember::query()->where('gabinete_id', $gabinete->id)->where('usuario_id', $user->id)->value('ativo'))->toBeTruthy()
        ->and(EntityMember::query()->where('entidade_id', $entidade->id)->where('usuario_id', $user->id)->value('ativo'))->toBeTruthy()
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

    expect(OfficeMember::query()->where('gabinete_id', $gabinete->id)->where('usuario_id', $user->id)->value('ativo'))->toBeTruthy()
        ->and(OfficeMember::query()->where('gabinete_id', $outro->id)->where('usuario_id', $user->id)->value('ativo'))->toBeTruthy();
});

/** Hub com uma Câmara (3) habilitada ou não para o GEP e seu gabinete raiz (12). */
function fingirEstruturaSobDemanda(bool $habilitado = true): void
{
    config([
        'services.hub.base_url' => 'https://hub.teste',
        'services.hub.api_secret' => str_repeat('k', 40),
    ]);

    Http::fake([
        'hub.teste/api/v1/entidades/3' => Http::response(['data' => [
            'id' => 3, 'nome' => 'Câmara de Sobral', 'slug' => 'camara-de-sobral',
            'classificacoes' => ['tipo' => 'CAMARA_MUNICIPAL'], 'status' => 'ativa',
            'municipio' => 'Sobral', 'estado' => 'CE', 'timezone' => 'America/Fortaleza',
            'habilitado' => $habilitado, 'atualizado_em' => now()->toIso8601String(),
        ]]),
        'hub.teste/api/v1/unidades/12' => Http::response(['data' => [
            'id' => 12, 'entidade_id' => 3, 'unidade_pai_id' => null,
            'nome' => 'Gabinete do Vereador Santos', 'slug' => 'gabinete-do-vereador-santos',
            'tipo' => 'GABINETE', 'ativa' => true, 'atualizado_em' => now()->toIso8601String(),
        ]]),
    ]);
}

it('cria sob demanda a entidade e o gabinete do vínculo ainda não espelhados', function () {
    fingirEstruturaSobDemanda();
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'administrador', 'ativo' => true,
    ]]);

    $entidade = Entity::query()->where('hub_entidade_id', '3')->firstOrFail();
    $gabinete = Office::withoutGlobalScopes()->where('hub_unidade_id', '12')->firstOrFail();

    expect($entidade->tipo)->toBe(EntityType::CityCouncil)
        ->and($gabinete->entidade_id)->toBe($entidade->id)
        ->and(vinculadoAoGabinete($user, $gabinete->id))->toBeTrue()
        ->and(vinculadoAEntidade($user, $entidade->id))->toBeTrue()
        ->and($user->fresh()->gabinete_id)->toBe($gabinete->id);
});

it('cria sob demanda só a entidade quando o vínculo é de entidade', function () {
    fingirEstruturaSobDemanda();
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicarVinculo($user, [
        'entidade_id' => '3', 'unidade_id' => null, 'papel' => 'operador', 'ativo' => true,
    ]);

    $entidade = Entity::query()->where('hub_entidade_id', '3')->firstOrFail();

    expect(vinculadoAEntidade($user, $entidade->id))->toBeTrue()
        ->and(Office::withoutGlobalScopes()->count())->toBe(0);
});

it('descarta o vínculo quando o Hub diz que o GEP não está habilitado na entidade', function () {
    fingirEstruturaSobDemanda(habilitado: false);
    $user = User::factory()->create(['gabinete_id' => null]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'administrador', 'ativo' => true,
    ]]);

    expect(Entity::query()->count())->toBe(0)
        ->and(Office::withoutGlobalScopes()->count())->toBe(0)
        ->and(EntityMember::query()->where('usuario_id', $user->id)->count())->toBe(0);
});

it('ignora o papel do Hub: qualquer valor só vincula, sem cargo e sem acesso', function (?string $papel) {
    [$entidade, $gabinete] = hubEstrutura();
    $entidade->forceFill(['tipo' => EntityType::IndependentOffice])->save();
    $user = User::factory()->create(['gabinete_id' => null, 'role' => UserRole::Operator]);

    hubSync()->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => $papel, 'ativo' => true,
    ]]);

    $membro = OfficeMember::query()->where('usuario_id', $user->id)->sole();

    expect(vinculadoAoGabinete($user, $gabinete->id))->toBeTrue()
        ->and(vinculadoAEntidade($user, $entidade->id))->toBeTrue()
        ->and($membro->papel)->toBeNull()
        ->and($membro->cargo)->toBeNull()
        ->and($user->fresh()->role)->toBe(UserRole::Operator)
        ->and($user->fresh()->canAccessGabinete($gabinete->id))->toBeFalse()
        ->and($user->fresh()->canManageGabinete($gabinete->id))->toBeFalse()
        ->and($user->fresh()->canManageEntidade($entidade->id))->toBeFalse();
})->with(['administrador', 'operador', 'auditor', 'root', 'vereador', null]);

it('preserva o cargo local quando o retrato do Hub muda o papel', function () {
    [, $gabinete] = hubEstrutura();
    $user = User::factory()->create(['gabinete_id' => null]);
    $link = ['entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true];

    hubSync()->aplicar($user, [$link]);
    OfficeMember::query()->where('usuario_id', $user->id)->update(['cargo' => OfficePosition::Councilor->value]);

    hubSync()->aplicar($user, [[...$link, 'papel' => 'auditor']]);
    hubSync()->aplicarVinculo($user, [...$link, 'papel' => 'administrador']);

    expect($user->fresh()->gabinetePosition($gabinete->id))->toBe(OfficePosition::Councilor)
        ->and($user->fresh()->canManageGabinete($gabinete->id))->toBeTrue();
});

it('reativa sem o cargo de liderança quando outra pessoa ativa já o ocupa', function () {
    [, $gabinete] = hubEstrutura();
    $user = User::factory()->create(['gabinete_id' => null]);
    $link = ['entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true];

    hubSync()->aplicar($user, [$link]);
    OfficeMember::query()->where('usuario_id', $user->id)->update(['cargo' => OfficePosition::Councilor->value]);
    hubSync()->aplicar($user, [[...$link, 'ativo' => false]]);

    $successor = User::factory()->forGabinete($gabinete)->withPosition(OfficePosition::Councilor)->create();

    hubSync()->aplicar($user, [$link]);

    expect(vinculadoAoGabinete($user, $gabinete->id))->toBeTrue()
        ->and($user->fresh()->gabinetePosition($gabinete->id))->toBeNull()
        ->and($successor->gabinetePosition($gabinete->id))->toBe(OfficePosition::Councilor);
});

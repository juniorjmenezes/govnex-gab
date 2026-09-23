<?php

use App\Enums\AccessRole;
use App\Enums\EntidadeType;
use App\Enums\UserRole;
use App\Models\Entidade;
use App\Services\Hub\HubPapelMapper;
use Illuminate\Support\Facades\Log;

function papelMapper(): HubPapelMapper
{
    return app(HubPapelMapper::class);
}

it('aceita os três papéis do vocabulário do ecossistema', function (string $papel, AccessRole $esperado) {
    Log::spy();

    expect(papelMapper()->accessRole($papel))->toBe($esperado);

    Log::shouldNotHaveReceived('warning');
})->with([
    'administrador' => ['administrador', AccessRole::Administrator],
    'operador' => ['operador', AccessRole::Operator],
    'auditor' => ['auditor', AccessRole::Auditor],
    'caixa e espaços' => ['  Administrador ', AccessRole::Administrator],
]);

it('rebaixa papel desconhecido a auditor e registra aviso', function (?string $papel) {
    Log::spy();

    expect(papelMapper()->accessRole($papel))->toBe(AccessRole::Auditor);

    Log::shouldHaveReceived('warning')->once();
})->with([
    'grafia antiga de líder' => ['LIDER'],
    'grafia antiga de vereador' => ['vereador'],
    'grafia antiga de chefe' => ['chefe_gabinete'],
    'vazio' => [''],
    'nulo' => [null],
]);

it('reconhece root como papel que não é vínculo', function () {
    expect(papelMapper()->isRoot('root'))->toBeTrue()
        ->and(papelMapper()->isRoot(' ROOT '))->toBeTrue()
        ->and(papelMapper()->isRoot('administrador'))->toBeFalse();
});

it('administrador de gabinete só administra a entidade quando ela é gabinete independente', function () {
    $independente = Entidade::factory()->create(['tipo' => EntidadeType::IndependentOffice]);
    $camara = Entidade::factory()->create(['tipo' => EntidadeType::CityCouncil]);

    expect(papelMapper()->entidadeRoleDeUnidade(AccessRole::Administrator, $independente))->toBe(AccessRole::Administrator)
        ->and(papelMapper()->entidadeRoleDeUnidade(AccessRole::Administrator, $camara))->toBe(AccessRole::Operator)
        ->and(papelMapper()->entidadeRoleDeUnidade(AccessRole::Operator, $independente))->toBe(AccessRole::Operator)
        ->and(papelMapper()->entidadeRoleDeUnidade(AccessRole::Auditor, $camara))->toBe(AccessRole::Auditor);
});

it('projeta o papel de acesso em users.role um para um', function () {
    expect(papelMapper()->userRole(AccessRole::Administrator))->toBe(UserRole::Administrator)
        ->and(papelMapper()->userRole(AccessRole::Operator))->toBe(UserRole::Operator)
        ->and(papelMapper()->userRole(AccessRole::Auditor))->toBe(UserRole::Auditor);
});

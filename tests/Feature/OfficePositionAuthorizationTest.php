<?php

use App\Authorization\OfficeAccess;
use App\Authorization\OfficeCapability;
use App\Enums\OfficePosition;
use App\Enums\OfficeType;
use App\Enums\UserRole;
use App\Models\AccessContextEvent;
use App\Models\Category;
use App\Models\Citizen;
use App\Models\Demand;
use App\Models\Entity;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Hub\HubVinculoSyncService;
use App\Services\Offices\OfficePositionAssigner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutVite();
});

function councilorOffice(): Office
{
    return Office::factory()->create(['tipo_gabinete' => OfficeType::CouncilorOffice]);
}

function memberWithPosition(Office $office, UserRole $role, ?OfficePosition $position): User
{
    $user = User::factory()->forGabinete($office)->create(['role' => $role]);

    OfficeMember::query()
        ->where('gabinete_id', $office->id)
        ->where('usuario_id', $user->id)
        ->update(['cargo' => $position?->value]);

    return $user->fresh();
}

function officeContextRoute(Office $office, string $name, array $parameters = []): string
{
    return route('context.'.$name, [
        'entidade' => $office->entidade->slug,
        'gabinete' => $office->slug,
        ...$parameters,
    ]);
}

/** @return array<string, bool> */
function capabilities(User $user): array
{
    return collect(OfficeCapability::cases())
        ->mapWithKeys(fn (OfficeCapability $c): array => [$c->value => app(OfficeAccess::class)->can($user, $c)])
        ->all();
}

it('restringe os cargos ao tipo de gabinete, com auditor em todos', function () {
    $councilorPositions = [OfficePosition::Councilor, OfficePosition::ChiefOfStaff, OfficePosition::Advisor, OfficePosition::Auditor];

    expect(OfficePosition::allowedFor(OfficeType::CouncilorOffice))->toBe($councilorPositions)
        ->and(OfficePosition::allowedFor(OfficeType::IndependentOffice))->toBe($councilorPositions)
        ->and(OfficePosition::allowedFor(OfficeType::LegislativeSecretariat))
        ->toBe([OfficePosition::Secretary, OfficePosition::Advisor, OfficePosition::Auditor]);

    foreach ([OfficeType::MayorOffice, OfficeType::Secretariat, OfficeType::AdministrativeDepartment] as $type) {
        expect(OfficePosition::allowedFor($type))->toBe([OfficePosition::Auditor]);
    }

    foreach (OfficeType::cases() as $type) {
        expect(OfficePosition::Auditor->isAllowedFor($type))->toBeTrue();
    }
});

it('recusa no backend cargo que não cabe no tipo do gabinete', function () {
    $office = councilorOffice();
    $admin = User::factory()->root()->create();
    $target = memberWithPosition($office, UserRole::Operator, null);

    $this->actingAs($admin)
        ->put(officeContextRoute($office, 'team.position.update', ['usuario' => $target->id]), [
            'cargo' => OfficePosition::Secretary->value,
        ])
        ->assertSessionHasErrors('cargo');

    $independent = Office::factory()->create(['tipo_gabinete' => OfficeType::MayorOffice]);
    $independentTarget = memberWithPosition($independent, UserRole::Operator, null);

    $this->actingAs($admin)
        ->put(officeContextRoute($independent, 'team.position.update', ['usuario' => $independentTarget->id]), [
            'cargo' => OfficePosition::Advisor->value,
        ])
        ->assertSessionHasErrors('cargo');

    expect($target->gabinetePosition($office->id))->toBeNull()
        ->and($independentTarget->gabinetePosition($independent->id))->toBeNull();
});

it('dá a vereador e chefe de gabinete exatamente os mesmos poderes, todos', function () {
    $office = councilorOffice();
    $councilor = memberWithPosition($office, UserRole::Operator, OfficePosition::Councilor);
    $chief = memberWithPosition($office, UserRole::Operator, OfficePosition::ChiefOfStaff);

    expect(capabilities($councilor))->toBe(capabilities($chief))
        ->and(array_unique(array_values(capabilities($councilor))))->toBe([true]);

    $demand = Demand::factory()->forGabinete($office)->create();
    $category = Category::factory()->forGabinete($office)->create();

    expect(Gate::forUser($chief)->allows('delete', $demand))->toBeTrue()
        ->and(Gate::forUser($chief)->allows('reassign', $demand))->toBeTrue()
        ->and(Gate::forUser($chief)->allows('update', $category))->toBeTrue()
        ->and(Gate::forUser($chief)->allows('viewAny', ReportExport::class))->toBeTrue()
        ->and(Gate::forUser($chief)->allows('viewAny', User::class))->toBeTrue();
});

it('deixa o assessor criar e editar, mas não excluir, redistribuir, exportar nem configurar', function () {
    $office = councilorOffice();
    // Mesmo administrador no Hub: com cargo, valem as permissões do cargo.
    $advisor = memberWithPosition($office, UserRole::Administrator, OfficePosition::Advisor);
    $demand = Demand::factory()->forGabinete($office)->create();
    $citizen = Citizen::factory()->forGabinete($office)->create();
    $category = Category::factory()->forGabinete($office)->create();
    $gate = Gate::forUser($advisor);

    expect($gate->allows('view', $demand))->toBeTrue()
        ->and($gate->allows('create', Demand::class))->toBeTrue()
        ->and($gate->allows('update', $demand))->toBeTrue()
        ->and($gate->allows('update', $citizen))->toBeTrue()
        ->and($gate->allows('delete', $demand))->toBeFalse()
        ->and($gate->allows('delete', $citizen))->toBeFalse()
        ->and($gate->allows('reassign', $demand))->toBeFalse()
        ->and($gate->allows('viewAny', ReportExport::class))->toBeFalse()
        ->and($gate->allows('update', $category))->toBeFalse()
        ->and($gate->allows('create', Category::class))->toBeFalse();
});

it('limita o secretário ao módulo da Secretaria legislativa (resto só leitura, pendente)', function () {
    $office = Office::factory()->create(['tipo_gabinete' => OfficeType::LegislativeSecretariat]);
    $secretary = memberWithPosition($office, UserRole::Operator, OfficePosition::Secretary);
    $demand = Demand::factory()->forGabinete($office)->create();

    expect(capabilities($secretary))->toBe([
        'writeRecords' => false,
        'deleteRecords' => false,
        'reassignDemands' => false,
        'exportReports' => false,
        'configureOffice' => false,
        'manageTeam' => false,
        'writeLegislativeSecretariat' => true,
        'deleteLegislativeSecretariat' => true,
        'moderateLegislativeSecretariat' => true,
    ])
        ->and(Gate::forUser($secretary)->allows('view', $demand))->toBeTrue()
        ->and(Gate::forUser($secretary)->allows('update', $demand))->toBeFalse();
});

it('nega tudo a vínculo sem cargo, qualquer que seja o papel do Hub (falha fechada)', function () {
    $office = councilorOffice();
    $demand = Demand::factory()->forGabinete($office)->create();

    foreach ([UserRole::Administrator, UserRole::Operator, UserRole::Auditor] as $role) {
        $member = memberWithPosition($office, $role, null);

        expect(array_unique(array_values(capabilities($member))))->toBe([false])
            ->and(app(OfficeAccess::class)->canAssignPositions($member))->toBeFalse()
            ->and($member->canAccessGabinete($office->id))->toBeFalse()
            ->and($member->canManageGabinete($office->id))->toBeFalse()
            ->and(Gate::forUser($member)->allows('update', $demand))->toBeFalse();

        $this->actingAs($member)
            ->get(officeContextRoute($office, 'demands.index'))
            ->assertForbidden();
    }
});

it('faz do cargo auditor somente leitura em qualquer tipo de gabinete', function () {
    foreach (OfficeType::cases() as $type) {
        $office = Office::factory()->create(['tipo_gabinete' => $type]);
        $auditor = memberWithPosition($office, UserRole::Administrator, OfficePosition::Auditor);
        $demand = Demand::factory()->forGabinete($office)->create();

        expect(array_unique(array_values(capabilities($auditor))))->toBe([false])
            ->and(app(OfficeAccess::class)->canAssignPositions($auditor))->toBeFalse()
            ->and($auditor->canAccessGabinete($office->id))->toBeTrue()
            ->and(Gate::forUser($auditor)->allows('view', $demand))->toBeTrue()
            ->and(Gate::forUser($auditor)->allows('update', $demand))->toBeFalse();
    }

    $office = councilorOffice();
    $auditor = memberWithPosition($office, UserRole::Operator, OfficePosition::Auditor);
    $demand = Demand::factory()->forGabinete($office)->create();

    $this->actingAs($auditor)
        ->get(officeContextRoute($office, 'demands.show', ['demanda' => $demand->id]))
        ->assertOk();
    $this->actingAs($auditor)
        ->delete(officeContextRoute($office, 'demands.destroy', ['demanda' => $demand->id]))
        ->assertForbidden();
});

it('ignora o papel do Hub: só o cargo conta', function () {
    $office = councilorOffice();
    $hubAuditorCouncilor = memberWithPosition($office, UserRole::Auditor, OfficePosition::Councilor);
    $hubAdminAdvisor = memberWithPosition($office, UserRole::Administrator, OfficePosition::Advisor);

    expect(array_unique(array_values(capabilities($hubAuditorCouncilor))))->toBe([true])
        ->and(capabilities($hubAdminAdvisor)['deleteRecords'])->toBeFalse()
        ->and(capabilities($hubAdminAdvisor)['configureOffice'])->toBeFalse();

    // O papel gravado no vínculo também não pesa (coluna inerte).
    OfficeMember::query()->where('usuario_id', $hubAdminAdvisor->id)->update(['papel' => 'ADMINISTRADOR']);
    expect(capabilities($hubAdminAdvisor->fresh())['deleteRecords'])->toBeFalse();
});

it('dá a um operador com cargo de vereador o que o cargo permite na requisição', function () {
    $office = councilorOffice();
    $councilor = memberWithPosition($office, UserRole::Operator, OfficePosition::Councilor);
    $demand = Demand::factory()->forGabinete($office)->create();

    $this->actingAs($councilor)
        ->get(officeContextRoute($office, 'team.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.permissions.deleteRecords', true)
            ->where('auth.permissions.assignPositions', true));

    $this->actingAs($councilor)
        ->delete(officeContextRoute($office, 'demands.destroy', ['demanda' => $demand->id]))
        ->assertRedirect();

    $this->assertSoftDeleted($demand);
});

it('exige redistribuir para trocar o responsável já definido, mas não para atribuir o primeiro', function () {
    $office = councilorOffice();
    $advisor = memberWithPosition($office, UserRole::Operator, OfficePosition::Advisor);
    $teammate = memberWithPosition($office, UserRole::Operator, null);
    $other = memberWithPosition($office, UserRole::Operator, OfficePosition::ChiefOfStaff);
    $unassigned = Demand::factory()->forGabinete($office)->create(['responsavel_id' => null]);
    $assigned = Demand::factory()->forGabinete($office)->create(['responsavel_id' => $teammate->id]);

    $payload = fn (Demand $demand, ?int $responsible): array => [
        'cidadao_id' => $demand->cidadao_id,
        'titulo' => $demand->titulo,
        'descricao' => $demand->descricao,
        'prioridade' => $demand->prioridade->value,
        'origem' => $demand->origem->value,
        'responsavel_id' => $responsible,
    ];

    $this->actingAs($advisor)
        ->put(officeContextRoute($office, 'demands.update', ['demanda' => $unassigned->id]), $payload($unassigned, $teammate->id))
        ->assertRedirect();
    expect($unassigned->fresh()->responsavel_id)->toBe($teammate->id);

    $this->actingAs($advisor)
        ->put(officeContextRoute($office, 'demands.update', ['demanda' => $assigned->id]), $payload($assigned, $teammate->id))
        ->assertRedirect();

    $this->actingAs($advisor)
        ->put(officeContextRoute($office, 'demands.update', ['demanda' => $assigned->id]), $payload($assigned, $other->id))
        ->assertForbidden();
    expect($assigned->fresh()->responsavel_id)->toBe($teammate->id);

    // Chefe de gabinete redistribui.
    $this->actingAs($other)
        ->put(officeContextRoute($office, 'demands.update', ['demanda' => $assigned->id]), $payload($assigned, $other->id))
        ->assertRedirect();
    expect($assigned->fresh()->responsavel_id)->toBe($other->id);
});

it('deixa root e vereador ou chefe de gabinete editarem o cargo, e registra a mudança', function () {
    $office = councilorOffice();
    $operator = memberWithPosition($office, UserRole::Operator, null);
    $advisor = memberWithPosition($office, UserRole::Administrator, OfficePosition::Advisor);
    $councilor = memberWithPosition($office, UserRole::Operator, OfficePosition::Councilor);
    $chief = memberWithPosition($office, UserRole::Operator, OfficePosition::ChiefOfStaff);
    $auditor = memberWithPosition($office, UserRole::Administrator, OfficePosition::Auditor);
    $target = memberWithPosition($office, UserRole::Operator, null);
    $root = User::factory()->root()->create();
    $route = officeContextRoute($office, 'team.position.update', ['usuario' => $target->id]);

    // Sem cargo de vereador/chefe não atribui — nem quem é administrador no
    // Hub com cargo de assessor ou de auditor.
    $this->actingAs($operator)->put($route, ['cargo' => OfficePosition::Advisor->value])->assertForbidden();
    $this->actingAs($advisor)->put($route, ['cargo' => OfficePosition::Advisor->value])->assertForbidden();
    $this->actingAs($auditor)->put($route, ['cargo' => OfficePosition::Advisor->value])->assertForbidden();
    expect($target->gabinetePosition($office->id))->toBeNull();

    // Vereador operador no Hub vê a Equipe e edita cargos.
    $this->actingAs($councilor)->get(officeContextRoute($office, 'team.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canAssignPositions', true));
    $this->actingAs($councilor)->put($route, ['cargo' => OfficePosition::Advisor->value])->assertRedirect();
    expect($target->gabinetePosition($office->id))->toBe(OfficePosition::Advisor);

    $event = AccessContextEvent::query()->where('evento', 'CARGO_ALTERADO')->latest('id')->first();
    expect($event)->not->toBeNull()
        ->and($event->usuario_id)->toBe($councilor->id)
        ->and($event->gabinete_id)->toBe($office->id)
        ->and($event->contexto)->toBe(['membro_id' => $target->id, 'de' => null, 'para' => 'ASSESSOR']);

    $this->actingAs($chief)->put($route, ['cargo' => null])->assertRedirect();
    expect($target->gabinetePosition($office->id))->toBeNull();

    $this->actingAs($root)->put($route, ['cargo' => OfficePosition::Auditor->value])->assertRedirect();
    expect($target->gabinetePosition($office->id))->toBe(OfficePosition::Auditor);

    // Pessoa de outro gabinete não é alcançável pela rota deste.
    $stranger = memberWithPosition(councilorOffice(), UserRole::Operator, null);
    $this->actingAs($councilor)
        ->put(officeContextRoute($office, 'team.position.update', ['usuario' => $stranger->id]), ['cargo' => OfficePosition::Advisor->value])
        ->assertNotFound();
});

it('não deixa mais o administrador do Hub atribuir o primeiro cargo', function () {
    $office = councilorOffice();
    $admin = memberWithPosition($office, UserRole::Administrator, null);
    $target = memberWithPosition($office, UserRole::Operator, null);

    $this->actingAs($admin)
        ->put(officeContextRoute($office, 'team.position.update', ['usuario' => $target->id]), ['cargo' => OfficePosition::Councilor->value])
        ->assertForbidden();

    expect($target->gabinetePosition($office->id))->toBeNull();
});

it('dá poder total ao root dentro de qualquer gabinete', function () {
    $office = councilorOffice();
    $root = User::factory()->root()->create();
    $demand = Demand::factory()->forGabinete($office)->create();

    $this->actingAs($root)
        ->get(officeContextRoute($office, 'demands.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canDelete', true)
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->every(fn ($allowed) => $allowed === true)));

    $this->actingAs($root)
        ->delete(officeContextRoute($office, 'demands.destroy', ['demanda' => $demand->id]))
        ->assertRedirect();
    $this->assertSoftDeleted($demand);

    $this->actingAs($root)->get(officeContextRoute($office, 'team.index'))->assertOk();
});

it('mostra o cargo na lista da equipe', function () {
    $office = councilorOffice();
    $admin = memberWithPosition($office, UserRole::Administrator, null);
    $advisor = memberWithPosition($office, UserRole::Operator, OfficePosition::Advisor);
    $chief = memberWithPosition($office, UserRole::Operator, OfficePosition::ChiefOfStaff);

    $this->actingAs($chief)
        ->get(officeContextRoute($office, 'team.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canAssignPositions', true)
            // Vereador fica fora do seletor de quem não é root.
            ->where('positionOptions', fn ($options) => ! collect($options)->contains('value', 'VEREADOR'))
            ->where('members', fn ($members) => collect($members)->firstWhere('id', $advisor->id)['position'] === 'ASSESSOR'
                && collect($members)->firstWhere('id', $admin->id)['position'] === null));
});

it('preserva o cargo local quando o Hub sincroniza o vínculo', function () {
    $entidade = Entity::factory()->create(['hub_entidade_id' => '3']);
    $office = Office::factory()->create([
        'entidade_id' => $entidade->id,
        'hub_unidade_id' => '12',
        'tipo_gabinete' => OfficeType::CouncilorOffice,
    ]);
    $user = User::factory()->create(['gabinete_id' => null]);
    $sync = app(HubVinculoSyncService::class);
    $link = ['entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true];

    $sync->aplicar($user, [$link]);
    OfficeMember::query()->where('usuario_id', $user->id)->update(['cargo' => OfficePosition::ChiefOfStaff->value]);

    $sync->aplicar($user, [[...$link, 'papel' => 'administrador']]);
    expect($user->fresh()->gabinetePosition($office->id))->toBe(OfficePosition::ChiefOfStaff)
        ->and(OfficeMember::query()->where('usuario_id', $user->id)->value('papel'))->toBeNull();

    // Encerrado e reativado no Hub: o cargo continua lá.
    $sync->aplicar($user, [[...$link, 'ativo' => false]]);
    $sync->aplicar($user, [$link]);
    expect(OfficeMember::query()->where('usuario_id', $user->id)->value('cargo'))->toBe(OfficePosition::ChiefOfStaff);
});

it('permite no máximo um vereador e um chefe de gabinete ativos por gabinete, em todos os caminhos', function () {
    $office = councilorOffice();
    $office->forceFill(['hub_unidade_id' => '12'])->save();
    $councilor = memberWithPosition($office, UserRole::Operator, OfficePosition::Councilor);
    $chief = memberWithPosition($office, UserRole::Operator, OfficePosition::ChiefOfStaff);
    $other = memberWithPosition($office, UserRole::Operator, null);
    $other->forceFill(['hub_user_id' => '99'])->saveQuietly();
    $root = User::factory()->root()->create();

    // Equipe do gabinete (vereador tentando outro chefe).
    $this->actingAs($councilor)
        ->put(officeContextRoute($office, 'team.position.update', ['usuario' => $other->id]), ['cargo' => 'CHEFE_GABINETE'])
        ->assertSessionHasErrors(['cargo' => "Este gabinete já tem um chefe de gabinete ativo ({$chief->name}). Remova ou altere o cargo dele antes."]);

    // Tela do root.
    $this->actingAs($root)
        ->put(route('admin.offices.positions.update', ['office' => $office->id, 'member' => $other->id]), ['cargo' => 'VEREADOR', 'motivo' => 'Segundo vereador'])
        ->assertSessionHasErrors(['cargo' => "Este gabinete já tem um vereador ativo ({$councilor->name}). Remova ou altere o cargo dele antes."]);

    // Comando de operador.
    $this->artisan('gab:cargo-conceder', ['hub_user_id' => '99', 'hub_unidade_id' => '12', 'cargo' => 'VEREADOR', '--motivo' => 'Segundo vereador'])
        ->expectsOutputToContain('já tem um vereador ativo')
        ->assertFailed();

    expect($other->gabinetePosition($office->id))->toBeNull();

    // O índice único do banco é a rede de segurança.
    expect(fn () => OfficeMember::query()->where('usuario_id', $other->id)->update(['cargo' => 'VEREADOR']))
        ->toThrow(UniqueConstraintViolationException::class);

    // Vínculo inativo com o cargo não conta.
    OfficeMember::query()->where('usuario_id', $councilor->id)->update(['ativo' => false]);
    $this->actingAs($root)
        ->put(route('admin.offices.positions.update', ['office' => $office->id, 'member' => $other->id]), ['cargo' => 'VEREADOR', 'motivo' => 'Novo vereador'])
        ->assertRedirect();
    expect($other->gabinetePosition($office->id))->toBe(OfficePosition::Councilor);
});

it('deixa o root substituir o vereador numa só operação, com uma só auditoria', function () {
    $office = councilorOffice();
    $councilor = memberWithPosition($office, UserRole::Operator, OfficePosition::Councilor);
    $other = memberWithPosition($office, UserRole::Operator, null);
    $root = User::factory()->root()->create();

    $this->actingAs($root)
        ->put(route('admin.offices.positions.update', ['office' => $office->id, 'member' => $other->id]), [
            'cargo' => 'VEREADOR', 'motivo' => 'Troca de titular', 'substituir' => true,
        ])
        ->assertRedirect();

    expect($other->gabinetePosition($office->id))->toBe(OfficePosition::Councilor)
        ->and(OfficeMember::query()->where('usuario_id', $councilor->id)->value('cargo'))->toBeNull();

    $events = AccessContextEvent::query()->where('evento', 'CARGO_CONCEDIDO_POR_OPERADOR')->get();
    expect($events)->toHaveCount(1)
        ->and($events->first()->contexto['substituido_id'])->toBe($councilor->id)
        ->and($events->first()->contexto['motivo'])->toBe('Troca de titular');
});

it('não deixa ninguém mexer no próprio cargo pela Equipe', function () {
    $office = councilorOffice();
    memberWithPosition($office, UserRole::Operator, OfficePosition::Councilor);
    $chief = memberWithPosition($office, UserRole::Operator, OfficePosition::ChiefOfStaff);

    $this->actingAs($chief)
        ->put(officeContextRoute($office, 'team.position.update', ['usuario' => $chief->id]), ['cargo' => 'ASSESSOR'])
        ->assertSessionHasErrors('cargo');

    expect($chief->gabinetePosition($office->id))->toBe(OfficePosition::ChiefOfStaff);
});

it('reserva ao root o cargo de vereador', function () {
    $office = councilorOffice();
    $councilor = memberWithPosition($office, UserRole::Operator, OfficePosition::Councilor);
    $chief = memberWithPosition($office, UserRole::Operator, OfficePosition::ChiefOfStaff);
    $other = memberWithPosition($office, UserRole::Operator, null);

    // Chefe não remove nem altera o vereador.
    $this->actingAs($chief)
        ->put(officeContextRoute($office, 'team.position.update', ['usuario' => $councilor->id]), ['cargo' => 'ASSESSOR'])
        ->assertSessionHasErrors(['cargo' => 'Só a equipe GOVNEX (root) atribui, altera ou remove o cargo de vereador.']);

    // Nem atribui vereador (mesmo com a vaga livre).
    OfficeMember::query()->where('usuario_id', $councilor->id)->update(['ativo' => false]);
    $this->actingAs($chief)
        ->put(officeContextRoute($office, 'team.position.update', ['usuario' => $other->id]), ['cargo' => 'VEREADOR'])
        ->assertSessionHasErrors('cargo');

    // Mas gerencia os demais cargos.
    $this->actingAs($chief)
        ->put(officeContextRoute($office, 'team.position.update', ['usuario' => $other->id]), ['cargo' => 'AUDITOR'])
        ->assertRedirect();

    expect($councilor->fresh()->gabinetes()->value('cargo'))->toBe(OfficePosition::Councilor)
        ->and($other->gabinetePosition($office->id))->toBe(OfficePosition::Auditor);
});

it('não deixa quem não é root remover o último vereador/chefe ativo', function () {
    $office = councilorOffice();
    $councilor = memberWithPosition($office, UserRole::Operator, OfficePosition::Councilor);
    $chief = memberWithPosition($office, UserRole::Operator, OfficePosition::ChiefOfStaff);
    $root = User::factory()->root()->create();

    // Sem vereador ativo, o chefe é o último líder: o vereador (inativo) não pode
    // tirá-lo, e o root pode.
    OfficeMember::query()->where('usuario_id', $councilor->id)->update(['ativo' => false, 'cargo' => null]);
    $advisorLeader = memberWithPosition($office, UserRole::Operator, OfficePosition::Councilor);
    OfficeMember::query()->where('usuario_id', $advisorLeader->id)->update(['ativo' => false]);

    $assigner = app(OfficePositionAssigner::class);
    $membership = OfficeMember::query()->where('usuario_id', $chief->id)->sole();

    expect(fn () => $assigner->assign($membership, OfficePosition::Advisor, 'CARGO_ALTERADO', $advisorLeader))
        ->toThrow(ValidationException::class, 'O gabinete não pode ficar sem vereador nem chefe de gabinete ativos.');

    $this->actingAs($root)
        ->put(route('admin.offices.positions.update', ['office' => $office->id, 'member' => $chief->id]), ['cargo' => null, 'motivo' => 'Encerramento'])
        ->assertRedirect();
    expect($chief->gabinetePosition($office->id))->toBeNull();
});

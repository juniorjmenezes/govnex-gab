<?php

use App\Enums\EntidadeStatus;
use App\Enums\GabineteStatus;
use App\Models\Entidade;
use App\Models\Gabinete;
use App\Models\User;
use App\Rules\DefinidoNoHub;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Cache::flush();
    Http::fake([
        'servicodados.ibge.gov.br/*' => Http::response([
            ['id' => 2304400, 'nome' => 'Fortaleza'],
        ]),
    ]);
    $this->withoutVite();
});

/** @return array<string, mixed> */
function payloadDoGabineteNoAdmin(Gabinete $office, array $overrides = []): array
{
    return [
        'nome' => $office->nome,
        'vereador_nome' => 'Maria da Silva',
        'numero_eleitoral' => '12345',
        'municipio' => 'Fortaleza',
        'estado' => 'CE',
        'timezone' => 'America/Sao_Paulo',
        'responsavel_nome' => 'Responsável',
        'responsavel_email' => 'responsavel@gabinete.test',
        'responsavel_password' => '',
        'responsavel_password_confirmation' => '',
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function payloadDasConfiguracoesDoGabinete(Gabinete $office, array $overrides = []): array
{
    return [
        'nome' => $office->nome,
        'vereador_nome' => 'Vereadora Marina',
        'municipio' => 'Fortaleza',
        'estado' => 'CE',
        'timezone' => 'America/Sao_Paulo',
        'usar_cor_padrao' => true,
        'remover_logo' => false,
        'formato_protocolo' => '{ANO}-{SEQUENCIAL}',
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function payloadDaIdentidadeDaEntidade(Entidade $entidade, array $overrides = []): array
{
    return [
        'name' => $entidade->nome,
        'timezone' => 'America/Fortaleza',
        'primary_color' => '#176B73',
        'secondary_color' => null,
        'simplified_interface' => false,
        'remove_logo' => false,
        ...$overrides,
    ];
}

it('recusa renomear no admin um gabinete ligado ao Hub, mas aceita os demais campos', function () {
    $admin = User::factory()->root()->create();
    $office = Gabinete::factory()->create(['nome' => 'Gabinete Santos', 'hub_unidade_id' => '71']);
    User::factory()->forGabinete($office)->administrator()->create();

    $this->actingAs($admin)
        ->put(route('admin.offices.update', $office), payloadDoGabineteNoAdmin($office, ['nome' => 'Outro nome']))
        ->assertSessionHasErrors(['nome' => DefinidoNoHub::MENSAGEM]);

    expect($office->fresh()->nome)->toBe('Gabinete Santos');

    $this->actingAs($admin)
        ->put(route('admin.offices.update', $office), payloadDoGabineteNoAdmin($office, ['vereador_nome' => 'Novo Titular']))
        ->assertSessionHasNoErrors();

    expect($office->fresh()->vereador_nome)->toBe('Novo Titular');
});

it('aceita renomear no admin um gabinete não ligado', function () {
    $admin = User::factory()->root()->create();
    $office = Gabinete::factory()->create(['nome' => 'Gabinete Antigo']);
    User::factory()->forGabinete($office)->administrator()->create();

    $this->actingAs($admin)
        ->put(route('admin.offices.update', $office), payloadDoGabineteNoAdmin($office, ['nome' => 'Gabinete Novo']))
        ->assertSessionHasNoErrors();

    expect($office->fresh()->nome)->toBe('Gabinete Novo')
        ->and($office->fresh()->entidade->nome)->toBe('Gabinete Novo');
});

it('não copia o nome do gabinete para a entidade independente ligada ao Hub', function () {
    $admin = User::factory()->root()->create();
    $entidade = Entidade::factory()->create(['nome' => 'Entidade do Hub', 'hub_entidade_id' => '7']);
    $office = Gabinete::factory()->create(['entidade_id' => $entidade->id, 'nome' => 'Gabinete Antigo']);
    User::factory()->forGabinete($office)->administrator()->create();

    $this->actingAs($admin)
        ->put(route('admin.offices.update', $office), payloadDoGabineteNoAdmin($office, ['nome' => 'Gabinete Novo']))
        ->assertSessionHasNoErrors();

    expect($office->fresh()->nome)->toBe('Gabinete Novo')
        ->and($entidade->fresh()->nome)->toBe('Entidade do Hub');
});

it('recusa suspender no admin um gabinete ligado ao Hub', function () {
    $admin = User::factory()->root()->create();
    $office = Gabinete::factory()->create(['hub_unidade_id' => '71']);

    $this->actingAs($admin)
        ->patch(route('admin.offices.status', $office), ['status' => GabineteStatus::Suspended->value])
        ->assertSessionHasErrors(['status' => DefinidoNoHub::MENSAGEM]);

    expect($office->fresh()->status)->toBe(GabineteStatus::Active);
});

it('suspende no admin um gabinete não ligado sem mexer na entidade ligada ao Hub', function () {
    $admin = User::factory()->root()->create();
    $entidade = Entidade::factory()->create(['hub_entidade_id' => '7']);
    $office = Gabinete::factory()->create(['entidade_id' => $entidade->id]);

    $this->actingAs($admin)
        ->patch(route('admin.offices.status', $office), ['status' => GabineteStatus::Suspended->value])
        ->assertSessionHasNoErrors();

    expect($office->fresh()->status)->toBe(GabineteStatus::Suspended)
        ->and($entidade->fresh()->status)->toBe(EntidadeStatus::Active);
});

it('recusa renomear nas configurações um gabinete ligado ao Hub e aceita os não ligados', function () {
    $linked = Gabinete::factory()->create(['nome' => 'Gabinete Santos', 'hub_unidade_id' => '71']);
    $councilor = User::factory()->administrator()->forGabinete($linked)->create();

    $this->actingAs($councilor)
        ->put(route('office-settings.update'), payloadDasConfiguracoesDoGabinete($linked, ['nome' => 'Outro nome']))
        ->assertSessionHasErrors(['nome' => DefinidoNoHub::MENSAGEM]);

    $this->actingAs($councilor)
        ->put(route('office-settings.update'), payloadDasConfiguracoesDoGabinete($linked, ['vereador_nome' => 'Vereador Novo']))
        ->assertSessionHasNoErrors();

    expect($linked->fresh()->nome)->toBe('Gabinete Santos')
        ->and($linked->fresh()->vereador_nome)->toBe('Vereador Novo');

    $free = Gabinete::factory()->create(['nome' => 'Gabinete Livre']);
    $other = User::factory()->administrator()->forGabinete($free)->create();

    $this->actingAs($other)
        ->put(route('office-settings.update'), payloadDasConfiguracoesDoGabinete($free, ['nome' => 'Gabinete Renomeado']))
        ->assertSessionHasNoErrors();

    expect($free->fresh()->nome)->toBe('Gabinete Renomeado');
});

it('recusa renomear a entidade ligada ao Hub e aceita a não ligada', function () {
    $linked = Gabinete::factory()->create();
    $linked->entidade->forceFill(['nome' => 'Câmara do Hub', 'hub_entidade_id' => '7'])->save();
    $manager = User::factory()->administrator()->forGabinete($linked)->create();
    $entidade = $linked->entidade->fresh();

    foreach (['entidades.identity.update' => 'post', 'entidades.update' => 'patch'] as $rota => $metodo) {
        $this->actingAs($manager)
            ->{$metodo}(route($rota, $entidade), payloadDaIdentidadeDaEntidade($entidade, ['name' => 'Outro nome']))
            ->assertSessionHasErrors(['name' => DefinidoNoHub::MENSAGEM]);
    }

    $this->actingAs($manager)
        ->post(route('entidades.identity.update', $entidade), payloadDaIdentidadeDaEntidade($entidade))
        ->assertSessionHasNoErrors();

    expect($entidade->fresh()->nome)->toBe('Câmara do Hub')
        ->and($entidade->fresh()->cor_principal)->toBe('#176B73');

    $free = Gabinete::factory()->create();
    $otherManager = User::factory()->administrator()->forGabinete($free)->create();

    $this->actingAs($otherManager)
        ->post(route('entidades.identity.update', $free->entidade), payloadDaIdentidadeDaEntidade($free->entidade, ['name' => 'Nome Local']))
        ->assertSessionHasNoErrors();

    expect($free->entidade->fresh()->nome)->toBe('Nome Local');
});

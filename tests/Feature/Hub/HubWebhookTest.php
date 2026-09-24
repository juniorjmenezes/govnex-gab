<?php

use App\Enums\AccessRole;
use App\Enums\EntidadeModule;
use App\Enums\EntidadeStatus;
use App\Enums\EntidadeType;
use App\Enums\GabineteStatus;
use App\Enums\GabineteType;
use App\Models\Entidade;
use App\Models\EntidadeLicenca;
use App\Models\EntidadeModulo;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use App\Services\Hub\HubVinculoSyncService;
use App\Services\Modules\GabineteModuleManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const HUB_WEBHOOK_PATH = '/api/integrations/hub/webhook';

beforeEach(function () {
    config([
        'services.hub.codigo' => 'GAB',
        'services.hub.api_secret' => str_repeat('k', 40),
        'services.hub.webhook_window_seconds' => 300,
    ]);
});

/**
 * Reproduz o `AssinadorDeWebhook` do Hub: canônico
 * `MÉTODO\nCAMINHO\nTIMESTAMP\nNONCE\nsha256(corpo)`.
 *
 * @return array<string, string>
 */
function assinarWebhookDoHub(string $corpo, ?string $segredo = null, ?int $timestamp = null): array
{
    $timestamp ??= time();
    $nonce = Str::random(32);
    $canonico = implode("\n", ['POST', HUB_WEBHOOK_PATH, (string) $timestamp, $nonce, hash('sha256', $corpo)]);

    return [
        'X-Hub-Timestamp' => (string) $timestamp,
        'X-Hub-Nonce' => $nonce,
        'X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $canonico, $segredo ?? config('services.hub.api_secret')),
        'Content-Type' => 'application/json',
    ];
}

/** @param  array<string, mixed>  $payload */
function enviarWebhookDoHub(array $payload, ?string $segredo = null, ?int $timestamp = null)
{
    $corpo = (string) json_encode($payload);

    return test()->call(
        'POST',
        HUB_WEBHOOK_PATH,
        [],
        [],
        [],
        collect(assinarWebhookDoHub($corpo, $segredo, $timestamp))
            ->mapWithKeys(fn (string $v, string $k): array => ['HTTP_'.str_replace('-', '_', strtoupper($k)) => $v])
            ->all(),
        $corpo,
    );
}

/** @param  array<string, mixed>  $dados */
function eventoDoHub(string $tipo, array $dados): array
{
    return [
        'id' => (string) Str::uuid(),
        'tipo' => $tipo,
        'sistema' => 'GAB',
        'ocorrido_em' => now()->toIso8601String(),
        'dados' => $dados,
    ];
}

/** @return array<string, mixed> */
function pessoaDoHub(string $id = '42', bool $ativo = true): array
{
    return [
        'id' => $id,
        'nome' => 'Ana Sousa',
        'email' => 'ana@exemplo.gov.br',
        'ativo' => $ativo,
        'email_verificado' => true,
        'atualizado_em' => now()->toIso8601String(),
    ];
}

it('recusa assinatura inválida', function () {
    enviarWebhookDoHub(
        eventoDoHub('pessoa.criada', ['pessoa' => pessoaDoHub()]),
        segredo: str_repeat('x', 40),
    )->assertStatus(401);

    expect(User::query()->where('hub_user_id', '42')->exists())->toBeFalse();
});

it('recusa assinatura fora da janela de tolerância', function () {
    enviarWebhookDoHub(
        eventoDoHub('pessoa.criada', ['pessoa' => pessoaDoHub()]),
        timestamp: time() - 3600,
    )->assertStatus(401);
});

it('recusa tipo de evento fora do vocabulário', function () {
    $evento = eventoDoHub('pessoa.criada', ['pessoa' => pessoaDoHub()]);
    $evento['tipo'] = 'pessoa.explodida';

    enviarWebhookDoHub($evento)->assertStatus(422);
});

it('recusa evento endereçado a outro sistema', function () {
    $evento = eventoDoHub('pessoa.criada', ['pessoa' => pessoaDoHub()]);
    $evento['sistema'] = 'GRI';

    enviarWebhookDoHub($evento)->assertStatus(422);
});

it('cria a pessoa em pessoa.criada', function () {
    enviarWebhookDoHub(eventoDoHub('pessoa.criada', ['pessoa' => pessoaDoHub()]))
        ->assertOk()
        ->assertJson(['ok' => true, 'acao' => 'pessoa_sincronizada']);

    $user = User::query()->where('hub_user_id', '42')->first();

    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('ana@exemplo.gov.br')
        ->and($user->is_active)->toBeTrue();
});

it('atualiza nome e e-mail em pessoa.alterada', function () {
    User::factory()->create(['hub_user_id' => '42', 'name' => 'Ana', 'email' => 'antigo@exemplo.gov.br']);

    enviarWebhookDoHub(eventoDoHub('pessoa.alterada', ['pessoa' => pessoaDoHub()]))->assertOk();

    $user = User::query()->where('hub_user_id', '42')->first();

    expect($user->name)->toBe('Ana Sousa')
        ->and($user->email)->toBe('ana@exemplo.gov.br');
});

it('desativa conta e vínculos em pessoa.desligada', function () {
    $entidade = Entidade::factory()->create(['hub_entidade_id' => '3']);
    $gabinete = Gabinete::factory()->create(['entidade_id' => $entidade->id, 'hub_unidade_id' => '12']);
    $user = User::factory()->create(['hub_user_id' => '42', 'email' => 'ana@exemplo.gov.br']);
    app(HubVinculoSyncService::class)->aplicar($user, [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true,
    ]]);

    enviarWebhookDoHub(eventoDoHub('pessoa.desligada', ['pessoa' => pessoaDoHub(ativo: false)]))
        ->assertOk()
        ->assertJson(['acao' => 'pessoa_desligada']);

    expect($user->fresh()->is_active)->toBeFalse()
        ->and(GabineteMembro::query()->where('gabinete_id', $gabinete->id)->where('usuario_id', $user->id)->value('ativo'))->toBeFalsy();
});

it('aplica vinculo.criado e vinculo.alterado', function () {
    $entidade = Entidade::factory()->create(['hub_entidade_id' => '3']);
    $gabinete = Gabinete::factory()->create(['entidade_id' => $entidade->id, 'hub_unidade_id' => '12']);

    $vinculo = [
        'id' => '7', 'entidade_id' => '3', 'unidade_id' => '12',
        'papel' => 'operador', 'escopo_hierarquico' => false, 'ativo' => true,
        'inicio_em' => null, 'fim_em' => null,
    ];

    enviarWebhookDoHub(eventoDoHub('vinculo.criado', [
        'pessoa' => pessoaDoHub(), 'vinculo' => $vinculo,
    ]))->assertOk()->assertJson(['acao' => 'vinculo_aplicado']);

    $user = User::query()->where('hub_user_id', '42')->first();
    expect($user->gabineteRole($gabinete->id))->toBe(AccessRole::Operator);

    enviarWebhookDoHub(eventoDoHub('vinculo.alterado', [
        'pessoa' => pessoaDoHub(), 'vinculo' => [...$vinculo, 'papel' => 'administrador'],
    ]))->assertOk();

    expect($user->fresh()->gabineteRole($gabinete->id))->toBe(AccessRole::Administrator);
});

it('encerra o vínculo em vinculo.encerrado mesmo se o retrato disser ativo', function () {
    $entidade = Entidade::factory()->create(['hub_entidade_id' => '3']);
    $gabinete = Gabinete::factory()->create(['entidade_id' => $entidade->id, 'hub_unidade_id' => '12']);
    $vinculo = ['id' => '7', 'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'operador', 'ativo' => true];

    enviarWebhookDoHub(eventoDoHub('vinculo.criado', ['pessoa' => pessoaDoHub(), 'vinculo' => $vinculo]))->assertOk();
    enviarWebhookDoHub(eventoDoHub('vinculo.encerrado', ['pessoa' => pessoaDoHub(), 'vinculo' => $vinculo]))->assertOk();

    $user = User::query()->where('hub_user_id', '42')->first();

    expect($user->gabineteRole($gabinete->id))->toBeNull()
        ->and(GabineteMembro::query()->where('gabinete_id', $gabinete->id)->where('usuario_id', $user->id)->exists())->toBeTrue();
});

it('descarta a reentrega do mesmo evento', function () {
    $evento = eventoDoHub('pessoa.criada', ['pessoa' => pessoaDoHub()]);

    enviarWebhookDoHub($evento)->assertOk()->assertJson(['acao' => 'pessoa_sincronizada']);
    enviarWebhookDoHub($evento)->assertOk()->assertJson(['duplicado' => true]);

    expect(User::query()->where('hub_user_id', '42')->count())->toBe(1);
});

it('recusa evento de vínculo sem bloco de vínculo', function () {
    enviarWebhookDoHub(eventoDoHub('vinculo.criado', ['pessoa' => pessoaDoHub()]))
        ->assertStatus(409);
});

/** @return array<string, mixed> */
function entidadeDoHub(array $overrides = []): array
{
    return [
        'id' => '3', 'conta_id' => '1', 'nome' => 'Gabinete Santos Renomeado',
        'slug' => 'gabinete-santos-renomeado', 'tipo' => 'CAMARA_MUNICIPAL',
        'status' => 'ativa', 'municipio' => 'Fortaleza', 'estado' => 'CE',
        'atualizado_em' => now()->toIso8601String(),
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function unidadeDoHub(array $overrides = []): array
{
    return [
        'id' => '12', 'entidade_id' => '3', 'unidade_pai_id' => null,
        'nome' => 'Gabinete do Vereador Santos', 'slug' => 'gabinete-do-vereador-santos',
        'tipo' => 'GABINETE', 'ativa' => true,
        'atualizado_em' => now()->toIso8601String(),
        ...$overrides,
    ];
}

it('renomeia a entidade ligada em entidade.alterada sem mexer no slug', function () {
    $entidade = Entidade::factory()->create(['hub_entidade_id' => '3', 'nome' => 'Gabinete Santos', 'slug' => 'gabinete-santos']);

    enviarWebhookDoHub(eventoDoHub('entidade.alterada', ['entidade' => entidadeDoHub()]))
        ->assertOk()
        ->assertJson(['ok' => true, 'acao' => 'entidade_sincronizada']);

    $entidade->refresh();

    expect($entidade->nome)->toBe('Gabinete Santos Renomeado')
        ->and($entidade->slug)->toBe('gabinete-santos')
        ->and($entidade->hub_sincronizado_em)->not->toBeNull();
});

it('suspende e reativa a entidade ligada conforme o status do Hub', function () {
    $entidade = Entidade::factory()->create(['hub_entidade_id' => '3']);

    enviarWebhookDoHub(eventoDoHub('entidade.alterada', ['entidade' => entidadeDoHub(['status' => 'suspensa'])]))->assertOk();

    expect($entidade->fresh()->status)->toBe(EntidadeStatus::Suspended)
        ->and($entidade->fresh()->suspensa_em)->not->toBeNull();

    enviarWebhookDoHub(eventoDoHub('entidade.alterada', ['entidade' => entidadeDoHub([
        'status' => 'ativa', 'atualizado_em' => now()->addMinute()->toIso8601String(),
    ])]))->assertOk();

    expect($entidade->fresh()->status)->toBe(EntidadeStatus::Active)
        ->and($entidade->fresh()->suspensa_em)->toBeNull();
});

it('suspende, sem apagar, a entidade removida no Hub', function () {
    $entidade = Entidade::factory()->create(['hub_entidade_id' => '3']);

    enviarWebhookDoHub(eventoDoHub('entidade.removida', ['entidade' => entidadeDoHub(['status' => 'ativa'])]))
        ->assertOk()
        ->assertJson(['acao' => 'entidade_sincronizada']);

    expect(Entidade::query()->find($entidade->id))->not->toBeNull()
        ->and($entidade->fresh()->status)->toBe(EntidadeStatus::Suspended);
});

it('descarta evento de entidade mais antigo que o último aplicado', function () {
    $entidade = Entidade::factory()->create([
        'hub_entidade_id' => '3', 'nome' => 'Nome Atual', 'hub_sincronizado_em' => now(),
    ]);

    enviarWebhookDoHub(eventoDoHub('entidade.alterada', ['entidade' => entidadeDoHub([
        'nome' => 'Nome Velho', 'status' => 'suspensa',
        'atualizado_em' => now()->subHour()->toIso8601String(),
    ])]))->assertOk()->assertJson(['acao' => 'evento_antigo_descartado']);

    expect($entidade->fresh()->nome)->toBe('Nome Atual')
        ->and($entidade->fresh()->status)->toBe(EntidadeStatus::Active);
});

it('ignora com 200 a entidade que não está ligada', function () {
    $entidade = Entidade::factory()->create(['nome' => 'Sem Ligação', 'slug' => 'gabinete-santos-renomeado']);

    enviarWebhookDoHub(eventoDoHub('entidade.alterada', ['entidade' => entidadeDoHub()]))
        ->assertOk()
        ->assertJson(['ok' => true, 'acao' => 'entidade_ignorada']);

    expect($entidade->fresh()->nome)->toBe('Sem Ligação');
});

it('cria a entidade em entidade.criada com slug próprio, licença e módulos, sem usuário', function () {
    Entidade::factory()->create(['slug' => 'camara-municipal-de-fortaleza']);
    $usuarios = User::query()->count();

    enviarWebhookDoHub(eventoDoHub('entidade.criada', ['entidade' => entidadeDoHub([
        'nome' => 'Câmara Municipal de Fortaleza', 'slug' => 'slug-do-hub',
        'timezone' => 'America/Fortaleza', 'sigla' => 'CMF',
    ])]))
        ->assertOk()
        ->assertJson(['ok' => true, 'acao' => 'entidade_criada']);

    $entidade = Entidade::query()->where('hub_entidade_id', '3')->firstOrFail();

    expect($entidade->tipo)->toBe(EntidadeType::CityCouncil)
        ->and($entidade->slug)->toBe('camara-municipal-de-fortaleza-2')
        ->and($entidade->nome)->toBe('Câmara Municipal de Fortaleza')
        ->and($entidade->municipio)->toBe('Fortaleza')
        ->and($entidade->estado)->toBe('CE')
        ->and($entidade->timezone)->toBe('America/Fortaleza')
        ->and($entidade->status)->toBe(EntidadeStatus::Active)
        ->and($entidade->interface_simplificada)->toBeFalse()
        ->and($entidade->hub_sincronizado_em)->not->toBeNull()
        ->and(EntidadeLicenca::query()->where('entidade_id', $entidade->id)->exists())->toBeTrue()
        ->and(EntidadeModulo::query()->where('entidade_id', $entidade->id)->count())->toBe(count(EntidadeModule::cases()))
        ->and(User::query()->count())->toBe($usuarios);
});

it('cria gabinete independente e prefeitura com os tipos equivalentes', function (string $tipo, EntidadeType $esperado, bool $simplificada) {
    enviarWebhookDoHub(eventoDoHub('entidade.criada', ['entidade' => entidadeDoHub(['tipo' => $tipo])]))
        ->assertOk()
        ->assertJson(['acao' => 'entidade_criada']);

    $entidade = Entidade::query()->where('hub_entidade_id', '3')->firstOrFail();

    expect($entidade->tipo)->toBe($esperado)
        ->and($entidade->interface_simplificada)->toBe($simplificada);
})->with([
    ['GABINETE_INDEPENDENTE', EntidadeType::IndependentOffice, true],
    ['PREFEITURA', EntidadeType::CityHall, false],
]);

it('ignora com 200 entidade de tipo sem equivalente no GAB', function () {
    enviarWebhookDoHub(eventoDoHub('entidade.criada', ['entidade' => entidadeDoHub(['tipo' => 'AUTARQUIA'])]))
        ->assertOk()
        ->assertJson(['ok' => true, 'acao' => 'entidade_ignorada', 'motivo' => 'tipo_sem_equivalente']);

    expect(Entidade::query()->count())->toBe(0);
});

it('não duplica entidade local ainda não ligada com o mesmo slug do Hub', function () {
    $local = Entidade::factory()->create(['slug' => 'gabinete-santos-renomeado']);

    enviarWebhookDoHub(eventoDoHub('entidade.criada', ['entidade' => entidadeDoHub()]))
        ->assertOk()
        ->assertJson(['acao' => 'entidade_ignorada', 'motivo' => 'casamento_pendente']);

    expect(Entidade::query()->count())->toBe(1)
        ->and($local->fresh()->hub_entidade_id)->toBeNull();
});

it('trata a reentrega de entidade.criada como atualização, sem duplicar', function () {
    enviarWebhookDoHub(eventoDoHub('entidade.criada', ['entidade' => entidadeDoHub()]))->assertOk();
    enviarWebhookDoHub(eventoDoHub('entidade.criada', ['entidade' => entidadeDoHub(['nome' => 'Nome Novo'])]))
        ->assertOk()
        ->assertJson(['acao' => 'entidade_sincronizada']);

    expect(Entidade::query()->where('hub_entidade_id', '3')->count())->toBe(1)
        ->and(Entidade::query()->where('hub_entidade_id', '3')->value('nome'))->toBe('Nome Novo');
});

it('deriva o tipo do gabinete da combinação entidade + unidade', function (EntidadeType $entidade, string $unidade, ?GabineteType $esperado) {
    $local = Entidade::factory()->create(['tipo' => $entidade, 'hub_entidade_id' => '3', 'municipio' => 'Sobral', 'estado' => 'CE', 'timezone' => 'America/Fortaleza']);
    $usuarios = User::query()->count();

    $resposta = enviarWebhookDoHub(eventoDoHub('unidade.criada', ['unidade' => unidadeDoHub(['tipo' => $unidade])]))->assertOk();

    $gabinete = Gabinete::withoutGlobalScopes()->where('hub_unidade_id', '12')->first();

    if ($esperado === null) {
        $resposta->assertJson(['acao' => 'unidade_ignorada', 'motivo' => 'tipo_sem_equivalente']);
        expect($gabinete)->toBeNull();

        return;
    }

    $resposta->assertJson(['acao' => 'unidade_criada']);

    expect($gabinete->tipo_gabinete)->toBe($esperado)
        ->and($gabinete->entidade_id)->toBe($local->id)
        ->and($gabinete->slug)->toBe('gabinete-do-vereador-santos')
        ->and($gabinete->municipio)->toBe('Sobral')
        ->and($gabinete->timezone)->toBe('America/Fortaleza')
        ->and($gabinete->vereador_nome)->toBeNull()
        ->and($gabinete->numero_eleitoral)->toBeNull()
        ->and(app(GabineteModuleManager::class)->activeFor($gabinete))->toBe(app(GabineteModuleManager::class)->allEnabled())
        ->and(User::query()->count())->toBe($usuarios)
        ->and(GabineteMembro::query()->where('gabinete_id', $gabinete->id)->count())->toBe(0);
})->with([
    [EntidadeType::CityCouncil, 'GABINETE', GabineteType::CouncilorOffice],
    [EntidadeType::CityCouncil, 'DIRETORIA', GabineteType::AdministrativeDepartment],
    [EntidadeType::CityCouncil, 'SECRETARIA', GabineteType::AdministrativeDepartment],
    [EntidadeType::CityHall, 'GABINETE', GabineteType::MayorOffice],
    [EntidadeType::CityHall, 'SECRETARIA', GabineteType::Secretariat],
    [EntidadeType::CityHall, 'SETOR', GabineteType::AdministrativeDepartment],
    [EntidadeType::IndependentOffice, 'GABINETE', GabineteType::IndependentOffice],
    [EntidadeType::IndependentOffice, 'SETOR', null],
    [EntidadeType::CityHall, 'ESCOLA', null],
    [EntidadeType::CityCouncil, 'OUTRA', null],
]);

it('ignora com 200 unidade aninhada', function () {
    Entidade::factory()->create(['tipo' => EntidadeType::CityCouncil, 'hub_entidade_id' => '3']);

    enviarWebhookDoHub(eventoDoHub('unidade.criada', ['unidade' => unidadeDoHub(['unidade_pai_id' => '11'])]))
        ->assertOk()
        ->assertJson(['acao' => 'unidade_ignorada', 'motivo' => 'unidade_aninhada']);

    expect(Gabinete::withoutGlobalScopes()->count())->toBe(0);
});

it('recusa um segundo gabinete em entidade independente', function () {
    $entidade = Entidade::factory()->create(['tipo' => EntidadeType::IndependentOffice, 'hub_entidade_id' => '3']);
    Gabinete::factory()->create(['entidade_id' => $entidade->id, 'tipo_gabinete' => GabineteType::IndependentOffice]);

    enviarWebhookDoHub(eventoDoHub('unidade.criada', ['unidade' => unidadeDoHub()]))
        ->assertOk()
        ->assertJson(['acao' => 'unidade_ignorada', 'motivo' => 'entidade_nao_aceita']);

    expect(Gabinete::withoutGlobalScopes()->where('entidade_id', $entidade->id)->count())->toBe(1);
});

it('reentrega de unidade.criada não duplica o gabinete', function () {
    Entidade::factory()->create(['tipo' => EntidadeType::CityCouncil, 'hub_entidade_id' => '3']);

    enviarWebhookDoHub(eventoDoHub('unidade.criada', ['unidade' => unidadeDoHub()]))->assertJson(['acao' => 'unidade_criada']);
    enviarWebhookDoHub(eventoDoHub('unidade.criada', ['unidade' => unidadeDoHub()]))->assertJson(['acao' => 'unidade_sincronizada']);

    expect(Gabinete::withoutGlobalScopes()->where('hub_unidade_id', '12')->count())->toBe(1);
});

it('resolve pela API do Hub a entidade de unidade que chegou antes dela', function () {
    config(['services.hub.base_url' => 'https://hub.teste']);
    Http::fake([
        'hub.teste/api/v1/entidades/3' => Http::response(['data' => [
            'id' => 3, 'nome' => 'Câmara de Sobral', 'slug' => 'camara-de-sobral',
            'classificacoes' => ['tipo' => 'CAMARA_MUNICIPAL'], 'status' => 'ativa',
            'municipio' => 'Sobral', 'estado' => 'CE', 'timezone' => 'America/Fortaleza',
            'habilitado' => true, 'atualizado_em' => now()->toIso8601String(),
        ]]),
    ]);

    enviarWebhookDoHub(eventoDoHub('unidade.criada', ['unidade' => unidadeDoHub()]))
        ->assertOk()
        ->assertJson(['acao' => 'unidade_criada']);

    $entidade = Entidade::query()->where('hub_entidade_id', '3')->firstOrFail();

    expect($entidade->nome)->toBe('Câmara de Sobral')
        ->and($entidade->gabinetes()->withoutGlobalScopes()->first()?->tipo_gabinete)->toBe(GabineteType::CouncilorOffice);
});

it('não cria a entidade que o Hub diz não estar habilitada para o GAB', function () {
    config(['services.hub.base_url' => 'https://hub.teste']);
    Http::fake([
        'hub.teste/api/v1/entidades/3' => Http::response(['data' => [
            'id' => 3, 'nome' => 'Câmara', 'classificacoes' => ['tipo' => 'CAMARA_MUNICIPAL'],
            'municipio' => 'Sobral', 'estado' => 'CE', 'habilitado' => false,
        ]]),
    ]);

    enviarWebhookDoHub(eventoDoHub('unidade.criada', ['unidade' => unidadeDoHub()]))
        ->assertOk()
        ->assertJson(['acao' => 'unidade_ignorada', 'motivo' => 'entidade_nao_espelhada']);

    expect(Entidade::query()->count())->toBe(0)
        ->and(Gabinete::withoutGlobalScopes()->count())->toBe(0);
});

it('responde 500 para o Hub reenviar quando não consegue resolver a entidade', function () {
    config(['services.hub.base_url' => 'https://hub.teste']);
    // O cliente tenta 3 vezes antes de desistir; a 4ª chamada é a reentrega.
    Http::fake(['hub.teste/api/v1/entidades/3' => Http::sequence()
        ->push([], 503)->push([], 503)->push([], 503)
        ->push(['data' => [
            'id' => 3, 'nome' => 'Câmara', 'classificacoes' => ['tipo' => 'CAMARA_MUNICIPAL'],
            'municipio' => 'Sobral', 'estado' => 'CE', 'habilitado' => true,
        ]]),
    ]);

    $evento = eventoDoHub('unidade.criada', ['unidade' => unidadeDoHub()]);

    enviarWebhookDoHub($evento)->assertStatus(500);

    expect(Gabinete::withoutGlobalScopes()->count())->toBe(0);

    // A falha libera o id: a reentrega é processada de novo.
    enviarWebhookDoHub($evento)->assertOk()->assertJson(['acao' => 'unidade_criada']);
});

it('renomeia, suspende e reativa o gabinete ligado pelos eventos de unidade', function () {
    $gabinete = Gabinete::factory()->create(['hub_unidade_id' => '12', 'nome' => 'Gabinete Antigo', 'slug' => 'gabinete-antigo']);

    enviarWebhookDoHub(eventoDoHub('unidade.alterada', ['unidade' => unidadeDoHub(['ativa' => false])]))
        ->assertOk()
        ->assertJson(['acao' => 'unidade_sincronizada']);

    $gabinete = Gabinete::withoutGlobalScopes()->find($gabinete->id);

    expect($gabinete->nome)->toBe('Gabinete do Vereador Santos')
        ->and($gabinete->slug)->toBe('gabinete-antigo')
        ->and($gabinete->status)->toBe(GabineteStatus::Suspended)
        ->and($gabinete->suspended_at)->not->toBeNull();

    enviarWebhookDoHub(eventoDoHub('unidade.alterada', ['unidade' => unidadeDoHub([
        'ativa' => true, 'atualizado_em' => now()->addMinute()->toIso8601String(),
    ])]))->assertOk();

    $gabinete = Gabinete::withoutGlobalScopes()->find($gabinete->id);

    expect($gabinete->status)->toBe(GabineteStatus::Active)
        ->and($gabinete->suspended_at)->toBeNull();
});

it('suspende, sem apagar, o gabinete da unidade removida no Hub', function () {
    $gabinete = Gabinete::factory()->create(['hub_unidade_id' => '12']);

    enviarWebhookDoHub(eventoDoHub('unidade.removida', ['unidade' => unidadeDoHub()]))->assertOk();

    $gabinete = Gabinete::withoutGlobalScopes()->find($gabinete->id);

    expect($gabinete)->not->toBeNull()
        ->and($gabinete->status)->toBe(GabineteStatus::Suspended);
});

it('ignora com 200 a unidade sem gabinete ligado', function () {
    enviarWebhookDoHub(eventoDoHub('unidade.alterada', ['unidade' => unidadeDoHub()]))
        ->assertOk()
        ->assertJson(['acao' => 'unidade_ignorada']);
});

it('recusa evento de estrutura sem o bloco correspondente', function () {
    enviarWebhookDoHub(eventoDoHub('entidade.alterada', ['unidade' => unidadeDoHub()]))
        ->assertStatus(409);
});

<?php

use App\Enums\EntidadeStatus;
use App\Enums\GabineteStatus;
use App\Models\Entidade;
use App\Models\Gabinete;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.hub.base_url' => 'https://hub.teste',
        'services.hub.api_secret' => str_repeat('k', 40),
    ]);
});

function fingirEstruturaDoHub(): void
{
    Http::fake([
        'hub.teste/api/v1/contas' => Http::response(['data' => [['id' => 1, 'slug' => 'conta']]]),
        'hub.teste/api/v1/contas/1/entidades' => Http::response(['data' => [
            ['id' => 7, 'slug' => 'camara-x'],
            ['id' => 8, 'slug' => 'sem-local'],
        ]]),
        'hub.teste/api/v1/entidades/7/unidades' => Http::response(['data' => [
            ['id' => 70, 'slug' => 'raiz', 'unidades' => [
                ['id' => 71, 'slug' => 'gab-a', 'unidades' => []],
            ]],
        ]]),
        'hub.teste/api/v1/entidades/8/unidades' => Http::response(['data' => []]),
    ]);
}

it('preenche as colunas de ponte casando por slug', function () {
    fingirEstruturaDoHub();
    $entidade = Entidade::factory()->create(['slug' => 'camara-x']);
    $gabinete = Gabinete::factory()->create(['entidade_id' => $entidade->id, 'slug' => 'gab-a']);

    $this->artisan('hub:espelhar-estrutura')->assertSuccessful();

    expect($entidade->fresh()->hub_entidade_id)->toBe('7')
        ->and(Gabinete::withoutGlobalScopes()->find($gabinete->id)->hub_unidade_id)->toBe('71');
});

it('não grava em dry-run', function () {
    fingirEstruturaDoHub();
    $entidade = Entidade::factory()->create(['slug' => 'camara-x']);

    $this->artisan('hub:espelhar-estrutura --dry-run')->assertSuccessful();

    expect($entidade->fresh()->hub_entidade_id)->toBeNull();
});

it('é idempotente e reporta conflito sem sobrescrever', function () {
    fingirEstruturaDoHub();
    $entidade = Entidade::factory()->create(['slug' => 'camara-x', 'hub_entidade_id' => '99']);

    $this->artisan('hub:espelhar-estrutura')->assertFailed();

    expect($entidade->fresh()->hub_entidade_id)->toBe('99');
});

it('roda de novo sem alterar o que já está preenchido', function () {
    fingirEstruturaDoHub();
    $entidade = Entidade::factory()->create(['slug' => 'camara-x']);

    $this->artisan('hub:espelhar-estrutura')->assertSuccessful();
    $this->artisan('hub:espelhar-estrutura')->assertSuccessful();

    expect($entidade->fresh()->hub_entidade_id)->toBe('7');
});

/** Estrutura com o contrato novo: suspensas incluídas, `status`/`ativa` e `atualizado_em`. */
function fingirEstruturaDoHubParaAtualizar(): void
{
    Http::fake([
        'hub.teste/api/v1/contas' => Http::response(['data' => [['id' => 1, 'slug' => 'conta']]]),
        'hub.teste/api/v1/contas/1/entidades?somente_ativas=false' => Http::response(['data' => [
            // Renomeada no Hub: o slug de lá mudou, o casamento é pelo id.
            ['id' => 7, 'slug' => 'camara-renomeada', 'nome' => 'Câmara Renomeada', 'status' => 'suspensa', 'atualizado_em' => now()->toIso8601String()],
        ]]),
        'hub.teste/api/v1/entidades/7/unidades?somente_ativas=false' => Http::response(['data' => [
            ['id' => 71, 'slug' => 'gab-novo', 'nome' => 'Gabinete Novo', 'ativa' => false, 'atualizado_em' => now()->toIso8601String(), 'unidades' => []],
        ]]),
    ]);
}

it('com --atualizar aplica nome e situação do Hub aos itens ligados, preservando o slug', function () {
    fingirEstruturaDoHubParaAtualizar();
    $entidade = Entidade::factory()->create(['slug' => 'camara-x', 'nome' => 'Câmara X', 'hub_entidade_id' => '7']);
    $gabinete = Gabinete::factory()->create(['entidade_id' => $entidade->id, 'slug' => 'gab-a', 'nome' => 'Gab A', 'hub_unidade_id' => '71']);

    $this->artisan('hub:espelhar-estrutura --atualizar')->assertSuccessful();

    $entidade->refresh();
    $gabinete = Gabinete::withoutGlobalScopes()->find($gabinete->id);

    expect($entidade->nome)->toBe('Câmara Renomeada')
        ->and($entidade->slug)->toBe('camara-x')
        ->and($entidade->status)->toBe(EntidadeStatus::Suspended)
        ->and($gabinete->nome)->toBe('Gabinete Novo')
        ->and($gabinete->slug)->toBe('gab-a')
        ->and($gabinete->status)->toBe(GabineteStatus::Suspended);
});

it('com --atualizar --dry-run só lista as divergências', function () {
    fingirEstruturaDoHubParaAtualizar();
    $entidade = Entidade::factory()->create(['slug' => 'camara-x', 'nome' => 'Câmara X', 'hub_entidade_id' => '7']);

    $this->artisan('hub:espelhar-estrutura --atualizar --dry-run')
        ->expectsOutputToContain('Câmara Renomeada')
        ->assertSuccessful();

    expect($entidade->fresh()->nome)->toBe('Câmara X')
        ->and($entidade->fresh()->status)->toBe(EntidadeStatus::Active);
});

it('sem --atualizar não mexe em nome nem situação', function () {
    fingirEstruturaDoHub();
    $entidade = Entidade::factory()->create(['slug' => 'camara-x', 'nome' => 'Câmara X', 'hub_entidade_id' => '7']);

    $this->artisan('hub:espelhar-estrutura')->assertSuccessful();

    expect($entidade->fresh()->nome)->toBe('Câmara X');
});

/** Uma entidade do Hub sem espelho: Câmara (9) habilitada, com um gabinete raiz, um setor aninhado e uma escola. */
function fingirEstruturaDoHubParaCriar(bool $habilitado = true): void
{
    Http::fake([
        'hub.teste/api/v1/contas' => Http::response(['data' => [['id' => 1, 'slug' => 'conta']]]),
        'hub.teste/api/v1/contas/1/entidades' => Http::response(['data' => [
            ['id' => 9, 'slug' => 'camara-nova', 'nome' => 'Câmara Nova'],
        ]]),
        'hub.teste/api/v1/entidades/9' => Http::response(['data' => [
            'id' => 9, 'nome' => 'Câmara Nova', 'slug' => 'camara-nova',
            'classificacoes' => ['tipo' => 'CAMARA_MUNICIPAL'], 'status' => 'ativa',
            'municipio' => 'Sobral', 'estado' => 'CE', 'timezone' => 'America/Fortaleza',
            'habilitado' => $habilitado,
        ]]),
        'hub.teste/api/v1/entidades/9/unidades' => Http::response(['data' => [
            ['id' => 90, 'entidade_id' => 9, 'unidade_pai_id' => null, 'slug' => 'gab-vereador', 'nome' => 'Gabinete do Vereador', 'tipo' => 'GABINETE', 'ativa' => true, 'unidades' => [
                ['id' => 91, 'entidade_id' => 9, 'unidade_pai_id' => 90, 'slug' => 'assessoria', 'nome' => 'Assessoria', 'tipo' => 'ASSESSORIA', 'ativa' => true, 'unidades' => []],
            ]],
            ['id' => 92, 'entidade_id' => 9, 'unidade_pai_id' => null, 'slug' => 'escola', 'nome' => 'Escola do Legislativo', 'tipo' => 'ESCOLA', 'ativa' => true, 'unidades' => []],
        ]]),
    ]);
}

it('com --criar --dry-run só lista o que nasceria', function () {
    fingirEstruturaDoHubParaCriar();

    $this->artisan('hub:espelhar-estrutura --criar --dry-run')
        ->expectsOutputToContain("Entidade 'camara-nova'")
        ->expectsOutputToContain("Gabinete 'camara-nova/gab-vereador'")
        ->assertSuccessful();

    expect(Entidade::query()->count())->toBe(0)
        ->and(Gabinete::withoutGlobalScopes()->count())->toBe(0);
});

it('com --criar cria a entidade habilitada e os gabinetes raiz com equivalente', function () {
    fingirEstruturaDoHubParaCriar();

    $this->artisan('hub:espelhar-estrutura --criar')->assertSuccessful();

    $entidade = Entidade::query()->where('hub_entidade_id', '9')->firstOrFail();
    $gabinetes = Gabinete::withoutGlobalScopes()->where('entidade_id', $entidade->id)->get();

    expect($entidade->nome)->toBe('Câmara Nova')
        ->and($gabinetes)->toHaveCount(1)
        ->and($gabinetes->first()->hub_unidade_id)->toBe('90');

    // Idempotente: rodar de novo não cria nada.
    $this->artisan('hub:espelhar-estrutura --criar')->assertSuccessful();

    expect(Entidade::query()->count())->toBe(1)
        ->and(Gabinete::withoutGlobalScopes()->count())->toBe(1);
});

it('com --criar não cria entidade em que o GAB não está habilitado', function () {
    fingirEstruturaDoHubParaCriar(habilitado: false);

    $this->artisan('hub:espelhar-estrutura --criar')
        ->expectsOutputToContain('gab_nao_habilitado')
        ->assertSuccessful();

    expect(Entidade::query()->count())->toBe(0);
});

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

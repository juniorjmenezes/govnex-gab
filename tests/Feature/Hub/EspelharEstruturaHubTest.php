<?php

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

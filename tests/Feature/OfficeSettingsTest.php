<?php

use App\Models\Bairro;
use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\Gabinete;
use App\Models\MunicipioEleitoral;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Cache::flush();
    Http::fake([
        'servicodados.ibge.gov.br/*' => Http::response([
            ['id' => 2303709, 'nome' => 'Caucaia'],
            ['id' => 2304400, 'nome' => 'Fortaleza'],
        ]),
    ]);
});

test('councilor updates office address and visual identity', function () {
    Storage::fake('public');
    $office = Gabinete::factory()->create([
        'municipio' => 'Caucaia',
        'estado' => 'CE',
        'numero_eleitoral' => '98765',
        'cor_principal' => '#0F766E',
    ]);
    $office->entidade->forceFill(['cor_principal' => '#176B73'])->save();
    $neighborhood = Bairro::factory()->forGabinete($office)->create([
        'municipio' => 'Caucaia',
        'estado' => 'CE',
    ]);
    $councilor = User::factory()->administrator()->forGabinete($office)->create();

    $this->actingAs($councilor)
        ->put(route('office-settings.update'), [
            'nome' => 'Gabinete Cidadão',
            'vereador_nome' => 'Vereadora Marina',
            // Tenant submissions must not override the admin-configured number.
            'numero_eleitoral' => '12345',
            'partido' => 'PSB',
            'legislatura' => '2025–2028',
            'municipio' => 'Fortaleza',
            'estado' => 'ce',
            'timezone' => 'America/Sao_Paulo',
            'telefone' => '(85) 3333-4444',
            'email' => 'contato@example.test',
            'endereco' => 'Rua das Flores',
            'numero' => '100',
            'bairro' => 'Centro',
            'cep' => '60000-000',
            'cor_principal' => '#0F766E',
            'usar_cor_padrao' => false,
            'remover_logo' => false,
            'formato_protocolo' => '{ANO}-{SEQUENCIAL}',
            'cabecalho_relatorios' => 'Gabinete Cidadão',
            'logo' => UploadedFile::fake()->image('logo.png', 240, 120),
        ])
        ->assertRedirect(route('office-settings.edit'))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Configurações do gabinete atualizadas.',
        ]);

    $office->refresh();

    expect($office->bairro)->toBe('Centro')
        ->and($office->endereco)->toBe('Rua das Flores')
        ->and($office->numero)->toBe('100')
        ->and($office->cep)->toBe('60000000')
        ->and($office->numero_eleitoral)->toBe('98765')
        ->and($office->cor_principal)->toBe('#0F766E')
        ->and($office->logo_path)->not->toBeNull()
        ->and($neighborhood->fresh()->municipio)->toBe('Fortaleza')
        ->and($neighborhood->fresh()->estado)->toBe('CE');
    Storage::disk('public')->assertExists($office->logo_path);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.gabinete.bairro', 'Centro')
            ->where('auth.user.gabinete.cor_principal', '#0F766E')
            ->where('auth.context.gabinete.primary_color', '#0F766E')
            ->where('auth.context.entidade.primary_color', '#176B73')
            ->where('auth.user.gabinete.logo_url', Storage::disk('public')->url($office->logo_path)));
});

test('councilor can view the configured electoral number', function () {
    $office = Gabinete::factory()->create(['numero_eleitoral' => '98765']);
    $councilor = User::factory()->administrator()->forGabinete($office)->create();

    $this->actingAs($councilor)
        ->get(route('office-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('office.numero_eleitoral', '98765')
            ->where('electoralCandidate.matched', false));
});

test('councilor sees the matched tse candidate once resolved', function () {
    $municipality = MunicipioEleitoral::query()->create([
        'codigo_tse' => '15890',
        'codigo_ibge' => '2304251',
        'nome' => 'Cruz',
        'uf' => 'CE',
    ]);
    $election = Eleicao::query()
        ->where('ano', 2024)
        ->where('tipo', 'municipal')
        ->firstOrFail();
    $titular = CandidatoPolitico::query()->create([
        'eleicao_id' => $election->id,
        'sq_candidato' => '60001945113',
        'abrangencia' => 'municipal',
        'municipio_eleitoral_id' => $municipality->id,
        'uf' => 'CE',
        'cargo' => 'Vereador',
        'nome' => 'MARCOS JOSE SILVEIRA',
        'nome_urna' => 'MARCOS SILVEIRA',
        'numero' => '11555',
        'partido_sigla' => 'PP',
    ]);
    $office = Gabinete::factory()->create([
        'municipio' => 'Cruz',
        'estado' => 'CE',
        'municipio_eleitoral_id' => $municipality->id,
        'numero_eleitoral' => '11555',
        'candidato_titular_id' => $titular->id,
    ]);
    $councilor = User::factory()->administrator()->forGabinete($office)->create();

    $this->actingAs($councilor)
        ->get(route('office-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('office.numero_eleitoral', '11555')
            ->where('electoralCandidate.matched', true)
            ->where('electoralCandidate.name', 'MARCOS SILVEIRA')
            ->where('electoralCandidate.party', 'PP'));
});

test('councilor restores the system identity and deletes the custom logo', function () {
    Storage::fake('public');
    $logoPath = UploadedFile::fake()->image('logo-atual.png')->store('gabinetes/atual', 'public');
    $office = Gabinete::factory()->create([
        'municipio' => 'Fortaleza',
        'estado' => 'CE',
        'logo_path' => $logoPath,
        'cor_principal' => '#0F766E',
    ]);
    $councilor = User::factory()->administrator()->forGabinete($office)->create();

    $this->actingAs($councilor)
        ->put(route('office-settings.update'), [
            'nome' => $office->nome,
            'vereador_nome' => $office->vereador_nome,
            'partido' => '',
            'legislatura' => '',
            'municipio' => $office->municipio,
            'estado' => $office->estado,
            'timezone' => 'America/Sao_Paulo',
            'telefone' => '',
            'email' => '',
            'endereco' => '',
            'bairro' => '',
            'cor_principal' => '#C44F00',
            'usar_cor_padrao' => true,
            'remover_logo' => true,
            'formato_protocolo' => '{ANO}-{SEQUENCIAL}',
            'cabecalho_relatorios' => '',
        ])
        ->assertRedirect(route('office-settings.edit'));

    $office->refresh();

    expect($office->logo_path)->toBeNull()
        ->and($office->cor_principal)->toBeNull();
    Storage::disk('public')->assertMissing($logoPath);
});

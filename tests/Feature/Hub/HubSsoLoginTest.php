<?php

use App\Enums\AccessRole;
use App\Enums\UserRole;
use App\Models\Entidade;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config([
        'services.hub.base_url' => 'https://hub.teste',
        'services.hub.codigo' => 'GAB',
        'services.hub.client_id' => 'client-de-teste',
        'services.hub.client_secret' => 'segredo-oidc-de-teste',
        'services.hub.api_secret' => str_repeat('k', 40),
    ]);
});

/** Substitui o handshake OIDC pelo perfil que o Hub devolveria. */
function fingirRetornoDoHub(string $sub, string $nome, string $email): void
{
    $hubUser = (new SocialiteUser)->setRaw([
        'sub' => $sub, 'name' => $nome, 'email' => $email, 'email_verified' => true,
    ])->map(['id' => $sub, 'name' => $nome, 'email' => $email]);

    $driver = Mockery::mock();
    $driver->shouldReceive('user')->andReturn($hubUser);
    Socialite::shouldReceive('driver')->with('hub')->andReturn($driver);
}

/** @param  array<int, array<string, mixed>>  $vinculos */
function fingirAcessoDoHub(string $sub, array $vinculos): void
{
    Http::fake([
        "https://hub.teste/api/v1/sistemas/GAB/pessoas/{$sub}" => Http::response([
            'data' => ['id' => $sub, 'nome' => 'Ana Sousa', 'email' => 'ana@exemplo.gov.br', 'ativo' => true, 'vinculos' => $vinculos],
        ]),
    ]);
}

it('casa a conta existente pelo e-mail e grava o hub_user_id', function () {
    $entidade = Entidade::factory()->create(['hub_entidade_id' => '3']);
    $gabinete = Gabinete::factory()->create(['entidade_id' => $entidade->id, 'hub_unidade_id' => '12']);
    $existente = User::factory()->create([
        'email' => 'ana@exemplo.gov.br',
        'hub_user_id' => null,
        'gabinete_id' => $gabinete->id,
        'role' => UserRole::Operator,
    ]);

    fingirRetornoDoHub('42', 'Ana Sousa', 'ana@exemplo.gov.br');
    fingirAcessoDoHub('42', [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'administrador',
    ]]);

    $this->get('/auth/hub/callback?code=abc&state=xyz')->assertRedirect(route('dashboard'));

    $existente->refresh();

    expect(auth()->id())->toBe($existente->id)
        ->and($existente->hub_user_id)->toBe('42')
        ->and($existente->gabineteRole($gabinete->id))->toBe(AccessRole::Administrator)
        ->and($existente->role)->toBe(UserRole::Administrator);
});

it('cria a conta quando a pessoa ainda não existe no GAB', function () {
    $entidade = Entidade::factory()->create(['hub_entidade_id' => '3']);
    Gabinete::factory()->create(['entidade_id' => $entidade->id, 'hub_unidade_id' => '12']);

    fingirRetornoDoHub('77', 'Bruno Lima', 'bruno@exemplo.gov.br');
    fingirAcessoDoHub('77', [[
        'entidade_id' => '3', 'unidade_id' => '12', 'papel' => 'administrador',
    ]]);

    $this->get('/auth/hub/callback?code=abc&state=xyz')->assertRedirect(route('dashboard'));

    $novo = User::query()->where('hub_user_id', '77')->first();

    expect($novo)->not->toBeNull()
        ->and($novo->email)->toBe('bruno@exemplo.gov.br')
        ->and($novo->email_verified_at)->not->toBeNull()
        ->and($novo->role)->toBe(UserRole::Administrator)
        ->and(auth()->id())->toBe($novo->id);
});

it('entra mesmo quando a API de vínculos do Hub está fora do ar', function () {
    $existente = User::factory()->create([
        'email' => 'ana@exemplo.gov.br',
        'hub_user_id' => '42',
    ]);

    fingirRetornoDoHub('42', 'Ana Sousa', 'ana@exemplo.gov.br');
    Http::fake(['https://hub.teste/*' => Http::response('', 500)]);

    $this->get('/auth/hub/callback?code=abc&state=xyz')->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($existente->id);
});

it('recusa conta desativada', function () {
    User::factory()->inactive()->create(['email' => 'ana@exemplo.gov.br', 'hub_user_id' => '42']);

    fingirRetornoDoHub('42', 'Ana Sousa', 'ana@exemplo.gov.br');
    fingirAcessoDoHub('42', []);

    $this->get('/auth/hub/callback?code=abc&state=xyz')->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('recusa e-mail que já pertence a outra pessoa do Hub', function () {
    User::factory()->create(['email' => 'ana@exemplo.gov.br', 'hub_user_id' => '99']);

    fingirRetornoDoHub('42', 'Ana Sousa', 'ana@exemplo.gov.br');
    fingirAcessoDoHub('42', []);

    $this->get('/auth/hub/callback?code=abc&state=xyz')->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse()
        ->and(User::query()->where('hub_user_id', '42')->exists())->toBeFalse();
});

it('não oferece o SSO quando o Hub não está configurado', function () {
    config(['services.hub.client_id' => null]);

    $this->get('/auth/hub/redirect')->assertRedirect(route('login'));
});

it('login local por senha fica restrito a root', function () {
    $assessor = User::factory()->create(['email' => 'assessor@exemplo.gov.br']);
    $root = User::factory()->root()->create(['email' => 'root@exemplo.gov.br']);

    $this->post('/login', ['email' => $assessor->email, 'password' => 'password'])
        ->assertSessionHasErrors();
    expect(auth()->check())->toBeFalse();

    $this->post('/login', ['email' => $root->email, 'password' => 'password']);
    expect(auth()->id())->toBe($root->id);
});

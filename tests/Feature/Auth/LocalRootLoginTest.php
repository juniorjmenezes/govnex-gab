<?php

use App\Auth\LocalRootLogin;
use App\Models\AccessContextEvent;
use App\Models\User;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

function configureHub(): void
{
    config([
        'services.hub.base_url' => 'https://hub.teste',
        'services.hub.client_id' => 'client-de-teste',
        'services.hub.client_secret' => 'segredo-oidc-de-teste',
    ]);
}

/** @return list<array{resultado: string, motivo: string}> */
function rootLoginEvents(): array
{
    return AccessContextEvent::query()
        ->where('evento', LocalRootLogin::EVENT)
        ->orderBy('id')
        ->get()
        ->map(fn (AccessContextEvent $e): array => ['resultado' => $e->resultado, 'motivo' => $e->contexto['motivo']])
        ->all();
}

it('recusa o root com a flag desligada e o Hub configurado, com mensagem genérica', function () {
    configureHub();
    config(['govnexgep.root_login_enabled' => false]);
    $root = User::factory()->root()->create();

    $this->post(route('login.store'), ['email' => $root->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
    $this->assertGuest();

    // Conta inexistente recebe a mesma resposta.
    $this->post(route('login.store'), ['email' => 'ninguem@exemplo.test', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $event = AccessContextEvent::query()->where('evento', LocalRootLogin::EVENT)->sole();
    expect($event->resultado)->toBe('NEGADO')
        ->and($event->usuario_id)->toBe($root->id)
        ->and($event->administrador_plataforma)->toBeTrue()
        ->and($event->contexto)->toMatchArray(['contingencia' => true, 'motivo' => 'desligado_por_flag', 'metodo' => 'senha'])
        ->and(json_encode($event->contexto))->not->toContain('password');
});

it('permite o root com a flag desligada quando o Hub não está configurado', function () {
    config(['govnexgep.root_login_enabled' => false, 'services.hub.client_id' => null]);
    $root = User::factory()->root()->create();

    $this->post(route('login.store'), ['email' => $root->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($root);

    expect(rootLoginEvents())->toBe([['resultado' => 'PERMITIDO', 'motivo' => 'sucesso']]);
});

it('permite o root com a flag ligada e o Hub configurado', function () {
    configureHub();
    config(['govnexgep.root_login_enabled' => true]);
    $root = User::factory()->root()->create();

    $this->post(route('login.store'), ['email' => $root->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($root);

    $event = AccessContextEvent::query()->where('evento', LocalRootLogin::EVENT)->sole();
    expect($event->resultado)->toBe('PERMITIDO')
        ->and($event->contexto)->toMatchArray(['contingencia' => true, 'motivo' => 'sucesso', 'metodo' => 'senha', 'flag_ligada' => true, 'hub_configurado' => true]);
});

it('nunca deixa não-root entrar por senha, com ou sem flag', function () {
    $operator = User::factory()->operator()->create();
    $admin = User::factory()->administrator()->create();

    foreach ([true, false] as $flag) {
        config(['govnexgep.root_login_enabled' => $flag]);

        foreach ([$operator, $admin] as $user) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
                ->assertSessionHasErrors('email');
            $this->assertGuest();
        }
    }

    expect(rootLoginEvents())->toBe([]);
});

it('audita senha errada do root', function () {
    configureHub();
    config(['govnexgep.root_login_enabled' => true]);
    $root = User::factory()->root()->create();

    $this->post(route('login.store'), ['email' => $root->email, 'password' => 'errada'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    expect(rootLoginEvents())->toBe([['resultado' => 'NEGADO', 'motivo' => 'credenciais_invalidas']]);
});

it('bloqueia a 6ª tentativa por e-mail e IP e audita o bloqueio', function () {
    configureHub();
    config(['govnexgep.root_login_enabled' => true]);
    $root = User::factory()->root()->create();

    foreach (range(1, 5) as $_) {
        $this->post(route('login.store'), ['email' => $root->email, 'password' => 'errada'])
            ->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => $root->email, 'password' => 'password'])
        ->assertStatus(429);
    $this->assertGuest();

    $events = rootLoginEvents();
    expect($events)->toHaveCount(6)
        ->and($events[5])->toBe(['resultado' => 'NEGADO', 'motivo' => 'bloqueado_por_tentativas']);
});

it('informa à tela de login se o formulário local aparece', function () {
    configureHub();
    config(['govnexgep.root_login_enabled' => false]);

    $this->get(route('login'))->assertInertia(fn ($page) => $page
        ->component('auth/login')
        ->where('hubEnabled', true)
        ->where('localLoginEnabled', false));

    config(['govnexgep.root_login_enabled' => true]);
    $this->get(route('login'))->assertInertia(fn ($page) => $page
        ->where('hubEnabled', true)
        ->where('localLoginEnabled', true));

    config(['govnexgep.root_login_enabled' => false, 'services.hub.base_url' => '']);
    $this->get(route('login'))->assertInertia(fn ($page) => $page
        ->where('hubEnabled', false)
        ->where('localLoginEnabled', true));
});

it('aplica a mesma regra ao login por passkey', function () {
    configureHub();
    $root = User::factory()->root()->create();
    $operator = User::factory()->operator()->create();
    $passkeyOf = fn (User $user) => (new Passkey)->setRelation('user', $user);
    $request = request();

    config(['govnexgep.root_login_enabled' => false]);
    expect(Passkeys::allowsLogin($request, $passkeyOf($root)))->toBeFalse();

    config(['govnexgep.root_login_enabled' => true]);
    expect(Passkeys::allowsLogin($request, $passkeyOf($root)))->toBeTrue()
        ->and(Passkeys::allowsLogin($request, $passkeyOf($operator)))->toBeFalse()
        ->and(rootLoginEvents())->toBe([['resultado' => 'NEGADO', 'motivo' => 'desligado_por_flag']]);
});

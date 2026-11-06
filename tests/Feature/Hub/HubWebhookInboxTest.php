<?php

use App\Enums\HubWebhookStatus;
use App\Models\HubWebhookEvent;
use App\Models\User;
use App\Services\Hub\HubEventProcessor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'services.hub.codigo' => 'GAB',
        'services.hub.api_secret' => str_repeat('k', 40),
        'services.hub.webhook_window_seconds' => 300,
        'services.hub.webhook_max_attempts' => 5,
    ]);
});

/** @param  array<string, mixed>  $payload */
function sendHubWebhook(array $payload, ?string $secret = null)
{
    $path = '/api/integrations/hub/webhook';
    $body = (string) json_encode($payload);
    $timestamp = (string) time();
    $nonce = Str::random(32);
    $canonical = implode("\n", ['POST', $path, $timestamp, $nonce, hash('sha256', $body)]);

    return test()->call('POST', $path, [], [], [], [
        'HTTP_X_HUB_TIMESTAMP' => $timestamp,
        'HTTP_X_HUB_NONCE' => $nonce,
        'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $canonical, $secret ?? config('services.hub.api_secret')),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

/** @return array<string, mixed> */
function personCreatedEvent(): array
{
    return [
        'id' => (string) Str::uuid(),
        'tipo' => 'pessoa.criada',
        'sistema' => 'GAB',
        'ocorrido_em' => now()->toIso8601String(),
        'dados' => ['pessoa' => [
            'id' => '42', 'nome' => 'Ana Sousa', 'email' => 'ana@exemplo.gov.br',
            'ativo' => true, 'email_verificado' => true, 'atualizado_em' => now()->toIso8601String(),
        ]],
    ];
}

function inboxRow(array $event): HubWebhookEvent
{
    return HubWebhookEvent::query()->where('evento_id', $event['id'])->sole();
}

function failingProcessor(): void
{
    test()->mock(HubEventProcessor::class)
        ->shouldReceive('processar')
        ->andThrow(new LogicException('detalhe com ana@exemplo.gov.br'));
}

function realProcessor(): void
{
    app()->forgetInstance(HubEventProcessor::class);
    app()->offsetUnset(HubEventProcessor::class);
}

it('grava o evento processado na caixa de entrada', function () {
    $event = personCreatedEvent();

    sendHubWebhook($event)->assertOk()->assertJson(['ok' => true, 'acao' => 'pessoa_sincronizada']);

    $row = inboxRow($event);
    expect($row->status)->toBe(HubWebhookStatus::Processed)
        ->and($row->tentativas)->toBe(1)
        ->and($row->tipo)->toBe('pessoa.criada')
        ->and($row->sistema)->toBe('GAB')
        ->and($row->resultado)->toBe('pessoa_sincronizada')
        ->and($row->payload['id'])->toBe($event['id'])
        ->and($row->recebido_em)->not->toBeNull()
        ->and($row->processado_em)->not->toBeNull()
        ->and($row->ocorrido_em)->not->toBeNull();
});

it('responde duplicado à reentrega de evento processado, sem processar de novo', function () {
    $event = personCreatedEvent();

    sendHubWebhook($event)->assertOk();
    sendHubWebhook($event)->assertOk()->assertExactJson(['ok' => true, 'duplicado' => true]);

    expect(HubWebhookEvent::query()->count())->toBe(1)
        ->and(inboxRow($event)->tentativas)->toBe(1)
        ->and(User::query()->where('hub_user_id', '42')->count())->toBe(1);
});

it('deixa reprocessável a falha nossa (500) e o comando reprocessa com sucesso', function () {
    failingProcessor();
    $event = personCreatedEvent();

    sendHubWebhook($event)->assertStatus(500);

    $row = inboxRow($event);
    expect($row->status)->toBe(HubWebhookStatus::TransientFailure)
        ->and($row->tentativas)->toBe(1)
        ->and($row->proxima_tentativa_em->isFuture())->toBeTrue()
        ->and($row->ultimo_erro)->toContain('LogicException')
        ->and($row->ultimo_erro)->not->toContain('ana@exemplo.gov.br');

    // Antes do backoff vencer, o comando não toca no evento.
    realProcessor();
    $this->artisan('hub:reprocessar-webhooks')->assertSuccessful();
    expect(inboxRow($event)->status)->toBe(HubWebhookStatus::TransientFailure);

    $this->travel(5)->minutes();
    $this->artisan('hub:reprocessar-webhooks')
        ->expectsOutputToContain('HTTP 200')
        ->assertSuccessful();

    expect(inboxRow($event)->status)->toBe(HubWebhookStatus::Processed)
        ->and(inboxRow($event)->tentativas)->toBe(2)
        ->and(User::query()->where('hub_user_id', '42')->exists())->toBeTrue();
});

it('reentrega do Hub depois de falha nossa processa de novo', function () {
    failingProcessor();
    $event = personCreatedEvent();
    sendHubWebhook($event)->assertStatus(500);

    realProcessor();
    sendHubWebhook($event)->assertOk()->assertJson(['acao' => 'pessoa_sincronizada']);

    expect(inboxRow($event)->status)->toBe(HubWebhookStatus::Processed);
});

it('não entra em loop com payload inaplicável (409)', function () {
    $event = [...personCreatedEvent(), 'tipo' => 'vinculo.criado'];

    sendHubWebhook($event)->assertStatus(409);
    expect(inboxRow($event)->status)->toBe(HubWebhookStatus::PermanentFailure);

    sendHubWebhook($event)->assertOk()->assertExactJson(['ok' => true, 'duplicado' => true]);

    $this->travel(2)->hours();
    $this->artisan('hub:reprocessar-webhooks')->expectsOutputToContain('0 evento(s)')->assertSuccessful();
    expect(inboxRow($event)->tentativas)->toBe(1);

    // Só o reprocesso explícito tenta de novo.
    $this->artisan('hub:reprocessar-webhooks', ['--id' => $event['id']])
        ->expectsOutputToContain('HTTP 409')
        ->assertFailed();
    expect(inboxRow($event)->tentativas)->toBe(2)
        ->and(inboxRow($event)->status)->toBe(HubWebhookStatus::PermanentFailure);
});

it('responde duplicado quando outra entrega está processando o mesmo evento', function () {
    $event = personCreatedEvent();
    HubWebhookEvent::query()->create([
        'evento_id' => $event['id'], 'tipo' => $event['tipo'], 'sistema' => 'GAB',
        'status' => HubWebhookStatus::Processing, 'tentativas' => 1, 'payload' => $event,
        'recebido_em' => now(), 'ultima_tentativa_em' => now(),
    ]);
    $this->mock(HubEventProcessor::class)->shouldNotReceive('processar');

    sendHubWebhook($event)->assertOk()->assertExactJson(['ok' => true, 'duplicado' => true]);

    expect(HubWebhookEvent::query()->count())->toBe(1);
});

it('garante a unicidade do evento no banco', function () {
    $event = personCreatedEvent();
    $attributes = [
        'evento_id' => $event['id'], 'tipo' => $event['tipo'], 'sistema' => 'GAB',
        'status' => HubWebhookStatus::Received, 'payload' => $event, 'recebido_em' => now(),
    ];

    HubWebhookEvent::query()->create($attributes);

    expect(fn () => HubWebhookEvent::query()->create($attributes))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('respeita o teto de tentativas', function () {
    config(['services.hub.webhook_max_attempts' => 2]);
    failingProcessor();
    $event = personCreatedEvent();

    sendHubWebhook($event)->assertStatus(500);
    expect(inboxRow($event)->status)->toBe(HubWebhookStatus::TransientFailure);

    $this->travel(5)->minutes();
    $this->artisan('hub:reprocessar-webhooks')->expectsOutputToContain('HTTP 500')->assertSuccessful();

    $row = inboxRow($event);
    expect($row->tentativas)->toBe(2)
        ->and($row->status)->toBe(HubWebhookStatus::PermanentFailure)
        ->and($row->ultimo_erro)->toStartWith('Tentativas esgotadas');

    $this->travel(2)->hours();
    $this->artisan('hub:reprocessar-webhooks')->expectsOutputToContain('0 evento(s)')->assertSuccessful();
    expect(inboxRow($event)->tentativas)->toBe(2);

    // A reentrega do Hub também não reabre o evento esgotado.
    sendHubWebhook($event)->assertOk()->assertJson(['duplicado' => true]);
});

it('libera e reprocessa evento preso em processamento', function () {
    $event = personCreatedEvent();
    HubWebhookEvent::query()->create([
        'evento_id' => $event['id'], 'tipo' => $event['tipo'], 'sistema' => 'GAB',
        'status' => HubWebhookStatus::Processing, 'tentativas' => 1, 'payload' => $event,
        'recebido_em' => now()->subHour(), 'ultima_tentativa_em' => now()->subHour(),
    ]);

    $this->artisan('hub:reprocessar-webhooks')->expectsOutputToContain('HTTP 200')->assertSuccessful();

    expect(inboxRow($event)->status)->toBe(HubWebhookStatus::Processed);
});

it('apaga pela retenção só os eventos encerrados antigos', function () {
    $make = fn (HubWebhookStatus $status, $when) => HubWebhookEvent::query()->forceCreate([
        'evento_id' => (string) Str::uuid(), 'tipo' => 'pessoa.criada', 'sistema' => 'GAB',
        'status' => $status, 'payload' => [], 'recebido_em' => $when,
        'processado_em' => $status === HubWebhookStatus::Processed ? $when : null,
        'created_at' => $when, 'updated_at' => $when,
    ]);

    $oldProcessed = $make(HubWebhookStatus::Processed, now()->subDays(31));
    $recentProcessed = $make(HubWebhookStatus::Processed, now()->subDays(5));
    $oldFailed = $make(HubWebhookStatus::PermanentFailure, now()->subDays(91));
    $recentFailed = $make(HubWebhookStatus::PermanentFailure, now()->subDays(31));
    $oldTransient = $make(HubWebhookStatus::TransientFailure, now()->subDays(200));

    $this->artisan('hub:limpar-webhooks', ['--dry-run' => true])->expectsOutputToContain('Seriam apagados 1 processado(s) e 1')->assertSuccessful();
    expect(HubWebhookEvent::query()->count())->toBe(5);

    $this->artisan('hub:limpar-webhooks')->assertSuccessful();

    expect(HubWebhookEvent::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$recentProcessed->id, $recentFailed->id, $oldTransient->id])->sort()->values()->all())
        ->and($oldProcessed->fresh())->toBeNull()
        ->and($oldFailed->fresh())->toBeNull();
});

it('não grava nada com assinatura inválida ou payload inválido', function () {
    sendHubWebhook(personCreatedEvent(), str_repeat('x', 40))->assertStatus(401);
    sendHubWebhook([...personCreatedEvent(), 'tipo' => 'desconhecido'])->assertStatus(422);

    expect(HubWebhookEvent::query()->count())->toBe(0);
});

<?php

namespace Tests\Feature;

use App\Enums\WhatsAppConsentAction;
use App\Enums\WhatsAppContactStatus;
use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppNotificationStatus;
use App\Enums\WhatsAppPurpose;
use App\Exceptions\WhatsAppGatewayException;
use App\Jobs\SendWhatsAppNotification;
use App\Jobs\SyncWhatsAppSuppression;
use App\Models\Cidadao;
use App\Models\Gabinete;
use App\Models\User;
use App\Models\WhatsAppCallbackEvent;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppTemplatePurpose;
use App\Services\Entidades\EntidadeQuotaService;
use App\Services\WhatsApp\WhatsAppConfigurationService;
use App\Services\WhatsApp\WhatsAppContactService;
use App\Services\WhatsApp\WhatsAppEligibilityService;
use App\Services\WhatsApp\WhatsAppGatewayClient;
use App\Services\WhatsApp\WhatsAppOutboxService;
use App\Services\WhatsApp\WhatsAppSensitiveDataPurger;
use App\Services\WhatsApp\WhatsAppTemplateCatalog;
use App\Services\WhatsApp\WhatsAppTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\ProvisionsEntidadeWhatsApp;
use Tests\TestCase;

class WhatsAppGatewayIntegrationTest extends TestCase
{
    use ProvisionsEntidadeWhatsApp, RefreshDatabase;

    private const REQUEST_SECRET = 'request-secret-with-at-least-32-characters';

    private const CALLBACK_SECRET = 'callback-secret-with-at-least-32-characters';

    private const PREVIOUS_CALLBACK_SECRET = 'previous-callback-secret-with-32-characters';

    private const PHONE_HASH_SECRET = 'phone-hash-secret-with-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set([
            'whatsapp.driver' => 'gateway',
            'whatsapp.real_enabled' => true,
            'whatsapp.gateway_url' => 'https://whatsapp.example.test',
            'whatsapp.gateway_account_id' => 6,
            'whatsapp.client_code' => 'GABINETE',
            'whatsapp.request_secret' => self::REQUEST_SECRET,
            'whatsapp.callback_secret' => self::CALLBACK_SECRET,
            'whatsapp.callback_secret_previous' => null,
            'whatsapp.callback_previous_valid_until' => null,
            'whatsapp.phone_hash_secret' => self::PHONE_HASH_SECRET,
        ]);
    }

    public function test_legacy_contact_permission_does_not_become_whatsapp_consent(): void
    {
        $office = Gabinete::factory()->create();
        Cidadao::factory()->forGabinete($office)->create(['consentimento_contato' => true]);

        $this->assertDatabaseCount('whatsapp_contatos', 0);
        $this->assertDatabaseCount('whatsapp_consentimentos', 0);
    }

    public function test_declared_contact_is_encrypted_and_consent_is_immutable_history(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->forGabinete($office)->create();

        $contact = app(WhatsAppContactService::class)
            ->declareForUser($user, '(88) 99999-1234', $user);

        $raw = DB::table('whatsapp_contatos')->where('id', $contact->id)->first();
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('5588999991234', (string) $raw->telefone_criptografado);
        $this->assertSame('5588999991234', $contact->telefone_criptografado);
        $this->assertSame('1234', $contact->telefone_final);
        $this->assertSame(WhatsAppContactStatus::Declared, $contact->status);
        $this->assertDatabaseHas('whatsapp_consentimentos', [
            'whatsapp_contato_id' => $contact->id,
            'acao' => WhatsAppConsentAction::Accepted->value,
            'versao' => config('whatsapp.consent.version'),
            'origem' => 'PROFILE',
        ]);
    }

    public function test_eligibility_is_isolated_by_office_and_fail_closed_by_mode(): void
    {
        [$office, , $contact] = $this->contactContext();
        $purpose = WhatsAppPurpose::DemandAssigned;
        $this->entidadeWhatsAppConnection($office);
        $this->readyEntidadeWhatsAppTemplate($office, $purpose);
        $configuration = app(WhatsAppConfigurationService::class);

        $configuration->update($office, WhatsAppMode::Off, '08:00', [$purpose->value]);
        $this->assertFalse(app(WhatsAppEligibilityService::class)->evaluate($contact, $purpose)['eligible']);

        $configuration->update($office, WhatsAppMode::Pilot, '08:00', [$purpose->value]);
        $this->assertFalse(app(WhatsAppEligibilityService::class)->evaluate($contact, $purpose)['eligible']);

        $contact->forceFill(['piloto' => true])->save();
        $this->assertTrue(app(WhatsAppEligibilityService::class)->evaluate($contact, $purpose)['eligible']);

        $otherOffice = Gabinete::factory()->create();
        $otherUser = User::factory()->forGabinete($otherOffice)->create();
        $otherContact = app(WhatsAppContactService::class)
            ->declareForUser($otherUser, '(85) 99999-8765', $otherUser);
        $this->assertFalse(app(WhatsAppEligibilityService::class)->evaluate($otherContact, $purpose)['eligible']);
    }

    public function test_outbox_is_idempotent_and_keeps_sensitive_parameters_encrypted(): void
    {
        [$office, , $contact] = $this->eligibleContext(WhatsAppPurpose::DemandAssigned);
        $outbox = app(WhatsAppOutboxService::class);

        $first = $outbox->enqueue(
            $contact,
            WhatsAppPurpose::DemandAssigned,
            'demand:10:assigned:20',
            ['Pessoa Teste', 'GAB-2026-0010', '05/08/2026'],
        );
        $second = $outbox->enqueue(
            $contact,
            WhatsAppPurpose::DemandAssigned,
            'demand:10:assigned:20',
            ['Pessoa Teste', 'GAB-2026-0010', '05/08/2026'],
        );

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame($office->id, $first->gabinete_id);
        $this->assertDatabaseCount('whatsapp_notificacoes', 1);
        $raw = DB::table('whatsapp_notificacoes')->where('id', $first->id)->first();
        $this->assertStringNotContainsString('Pessoa Teste', (string) $raw->parametros_corpo_criptografados);
        $this->assertStringNotContainsString('5588999991234', (string) $raw->telefone_criptografado);
    }

    public function test_callback_rejects_invalid_expired_and_replayed_signatures(): void
    {
        $payload = $this->inboundPayload();

        $this->postSignedCallback($payload, 'invalid-secret-with-at-least-32-characters')
            ->assertUnauthorized();
        $this->postSignedCallback($payload, self::CALLBACK_SECRET, now()->subMinutes(10)->timestamp)
            ->assertUnauthorized();

        $nonce = 'nonce-replay-safe-1234567890';
        $this->postSignedCallback($payload, self::CALLBACK_SECRET, now()->timestamp, $nonce)
            ->assertOk();
        $this->postSignedCallback($payload, self::CALLBACK_SECRET, now()->timestamp, $nonce)
            ->assertUnauthorized();
    }

    public function test_previous_callback_secret_is_accepted_only_during_compatibility_window(): void
    {
        config()->set([
            'whatsapp.callback_secret_previous' => self::PREVIOUS_CALLBACK_SECRET,
            'whatsapp.callback_previous_valid_until' => now()->addHour()->toIso8601String(),
        ]);

        $this->postSignedCallback($this->inboundPayload(), self::PREVIOUS_CALLBACK_SECRET)
            ->assertOk();

        config()->set('whatsapp.callback_previous_valid_until', now()->subMinute()->toIso8601String());
        $this->postSignedCallback($this->inboundPayload(), self::PREVIOUS_CALLBACK_SECRET)
            ->assertUnauthorized();
    }

    public function test_callback_event_is_idempotent_and_rejects_divergent_reuse(): void
    {
        $payload = $this->inboundPayload();

        $this->postSignedCallback($payload)->assertJsonPath('duplicate', false);
        $this->postSignedCallback($payload)->assertJsonPath('duplicate', true);

        $payload['data']['phone_hash'] = str_repeat('b', 64);
        $this->postSignedCallback($payload)->assertConflict();
        $this->assertDatabaseCount('whatsapp_callback_eventos', 1);
    }

    public function test_delivery_callbacks_are_monotonic_and_unknown_status_is_not_downgraded(): void
    {
        [, , $contact] = $this->eligibleContext(WhatsAppPurpose::DemandAssigned);
        $notification = app(WhatsAppOutboxService::class)->enqueue(
            $contact,
            WhatsAppPurpose::DemandAssigned,
            'demand:20:assigned:30',
            ['Pessoa Teste', 'GAB-2026-0020', '05/08/2026'],
        );
        $this->assertNotNull($notification);
        $notification->forceFill(['status' => WhatsAppNotificationStatus::Submitted])->save();

        $this->postSignedCallback($this->statusPayload($notification, 'DELIVERED'))->assertOk();
        $this->assertSame(WhatsAppNotificationStatus::Delivered, $notification->fresh()->status);

        $this->postSignedCallback($this->statusPayload($notification, 'SENT'))->assertOk();
        $this->assertSame(WhatsAppNotificationStatus::Delivered, $notification->fresh()->status);

        $unknown = $this->statusPayload($notification, 'UNKNOWN_PROVIDER_STATE');
        $this->postSignedCallback($unknown)->assertConflict();
        $this->assertSame(WhatsAppNotificationStatus::Delivered, $notification->fresh()->status);
        $this->assertSame('ERROR', WhatsAppCallbackEvent::query()->where('event_id', $unknown['event_id'])->value('status'));
    }

    public function test_opt_out_revokes_all_local_delivery_eligibility_by_stable_hash(): void
    {
        [, , $contact] = $this->eligibleContext(WhatsAppPurpose::DemandAssigned);

        $payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'CONTACT_OPTOUT',
            'occurred_at' => now()->toIso8601String(),
            'data' => ['phone_hash' => $contact->telefone_hash],
        ];
        $this->postSignedCallback($payload)->assertOk();

        $contact->refresh();
        $this->assertSame(WhatsAppContactStatus::Revoked, $contact->status);
        $this->assertFalse($contact->piloto);
        $this->assertFalse($contact->hasCurrentConsent());
        $this->assertDatabaseHas('whatsapp_consentimentos', [
            'whatsapp_contato_id' => $contact->id,
            'acao' => WhatsAppConsentAction::Revoked->value,
            'origem' => 'INBOUND_OPTOUT',
        ]);
    }

    public function test_system_revocation_is_global_by_phone_and_release_waits_for_all_consents(): void
    {
        $firstOffice = Gabinete::factory()->create();
        $firstUser = User::factory()->forGabinete($firstOffice)->create();
        $secondOffice = Gabinete::factory()->create();
        $secondUser = User::factory()->forGabinete($secondOffice)->create();
        $contacts = app(WhatsAppContactService::class);
        $first = $contacts->declareForUser($firstUser, '(88) 99999-1234', $firstUser);
        $second = $contacts->declareForUser($secondUser, '(88) 99999-1234', $secondUser);

        $contacts->revoke($first, $firstUser);

        $this->assertSame(WhatsAppContactStatus::Revoked, $first->fresh()->status);
        $this->assertSame(WhatsAppContactStatus::Revoked, $second->fresh()->status);
        $this->assertDatabaseHas('whatsapp_consentimentos', [
            'whatsapp_contato_id' => $second->id,
            'acao' => WhatsAppConsentAction::Revoked->value,
            'origem' => 'PROFILE',
        ]);

        $first = $contacts->declareForUser($firstUser, '(88) 99999-1234', $firstUser);
        Http::fake(['*' => Http::response(['ok' => true])]);
        (new SyncWhatsAppSuppression($first->id, $firstUser->id))->handle(
            app(WhatsAppGatewayClient::class),
        );
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'PUT');

        $second = $contacts->declareForUser($secondUser, '(88) 99999-1234', $secondUser);
        Http::fake(['*' => Http::response(['ok' => true])]);
        (new SyncWhatsAppSuppression($second->id, $secondUser->id))->handle(
            app(WhatsAppGatewayClient::class),
        );
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE');
    }

    public function test_gateway_client_signs_requests_and_rejects_noncanonical_base_url(): void
    {
        Http::fake(function (ClientRequest $request) {
            $timestamp = $request->header('X-WhatsApp-Timestamp')[0] ?? '';
            $nonce = $request->header('X-WhatsApp-Nonce')[0] ?? '';
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $canonical = "GET\n{$path}\n{$timestamp}\n{$nonce}\n".hash('sha256', '');
            $expected = 'sha256='.hash_hmac('sha256', $canonical, self::REQUEST_SECRET);

            $this->assertSame('GABINETE', $request->header('X-WhatsApp-Client')[0] ?? null);
            $this->assertSame($expected, $request->header('X-WhatsApp-Signature')[0] ?? null);

            return Http::response(['templates' => []]);
        });

        $this->assertSame([], app(WhatsAppGatewayClient::class)->templates());

        config()->set('whatsapp.gateway_url', 'https://whatsapp.example.test/subpath');
        $this->expectException(RuntimeException::class);
        app(WhatsAppGatewayClient::class)->templates();
    }

    public function test_template_sync_marks_changed_contract_as_conflict(): void
    {
        $purpose = WhatsAppPurpose::DemandAssigned;
        $definition = app(WhatsAppTemplateCatalog::class)->definition($purpose);
        app(WhatsAppTemplateService::class)->catalog();
        $components = $definition['components'];
        $components[0]['text'] = 'Conteúdo adulterado {{1}} {{2}} {{3}}.';
        Http::fake([
            '*' => Http::response(['templates' => [[
                'id' => 99,
                'account_id' => 6,
                'purpose' => $purpose->value,
                'name' => $purpose->metaName(),
                'language' => 'pt_BR',
                'category' => 'UTILITY',
                'status' => 'APPROVED',
                'components' => $components,
            ]]]),
        ]);

        app(WhatsAppTemplateService::class)->sync();

        $template = WhatsAppTemplatePurpose::query()->where('finalidade', $purpose->value)->firstOrFail();
        $this->assertSame('CONFLICT', $template->status);
        $this->assertFalse($template->ativo);
    }

    public function test_template_catalog_rejects_variables_at_the_effective_edges(): void
    {
        $catalog = app(WhatsAppTemplateCatalog::class);
        $purpose = WhatsAppPurpose::AppointmentCitizenReminder;

        try {
            $catalog->definition(
                $purpose,
                '{{1}} confirmou o compromisso em {{2}}, às {{3}}. Local: {{4}}.',
            );
            $this->fail('O catálogo deveria rejeitar variável no início ou no final efetivo.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('início ou no final', $exception->getMessage());
        }

        $definition = $catalog->definition($purpose);
        $body = (string) $definition['components'][0]['text'];
        $this->assertStringContainsString('Em caso de dúvida', $body);
        $this->assertDoesNotMatchRegularExpression('/\{\{\d+\}\}[\s\p{P}]*$/u', $body);
    }

    public function test_ambiguous_template_submission_is_persisted_before_the_error_returns(): void
    {
        $purpose = WhatsAppPurpose::DemandAssigned;
        $definition = app(WhatsAppTemplateCatalog::class)->definition($purpose);
        $template = WhatsAppTemplatePurpose::query()->create([
            'finalidade' => $purpose->value,
            'gateway_template_id' => 77,
            'nome_meta' => $definition['meta_name'],
            'idioma' => 'pt_BR',
            'categoria' => 'UTILITY',
            'status' => 'DRAFT',
            'componentes' => $definition['components'],
            'contrato_hash' => $definition['contract_hash'],
            'ativo' => false,
        ]);
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'message' => 'Resultado externo ainda nao confirmado.',
                'ambiguous' => true,
                'retryable' => false,
            ], 409),
        ]);

        try {
            app(WhatsAppTemplateService::class)->submit($template);
            $this->fail('A resposta ambigua deveria interromper a submissao local.');
        } catch (WhatsAppGatewayException $exception) {
            $this->assertTrue($exception->ambiguous);
            $this->assertFalse($exception->retryable);
        }

        $this->assertSame('SUBMISSION_AMBIGUOUS', $template->fresh()->status);
        $this->assertFalse($template->fresh()->ativo);
        $this->assertNotNull($template->fresh()->sincronizado_em);
        Http::assertSentCount(1);
    }

    public function test_ambiguous_send_is_reconciled_before_any_second_post(): void
    {
        [, , $contact] = $this->eligibleContext(WhatsAppPurpose::DemandAssigned);
        $notification = app(WhatsAppOutboxService::class)->enqueue(
            $contact,
            WhatsAppPurpose::DemandAssigned,
            'demand:30:assigned:40',
            ['Pessoa Teste', 'GAB-2026-0030', '05/08/2026'],
        );
        $this->assertNotNull($notification);

        $phase = 'send';
        Http::fake(function (ClientRequest $request) use (&$phase, $notification) {
            if ($phase === 'send') {
                $this->assertSame('POST', $request->method());
                $phase = 'reconcile';
                throw new ConnectionException('timeout');
            }
            $this->assertSame('GET', $request->method());

            return Http::response(['message' => [
                'client_request_id' => $notification->client_request_id,
                'status' => 'QUEUED',
            ]]);
        });
        (new SendWhatsAppNotification($notification->id))->handle(
            app(WhatsAppGatewayClient::class),
            app(WhatsAppEligibilityService::class),
            app(EntidadeQuotaService::class),
        );
        $this->assertSame(WhatsAppNotificationStatus::Reconciling, $notification->fresh()->status);

        (new SendWhatsAppNotification($notification->id))->handle(
            app(WhatsAppGatewayClient::class),
            app(WhatsAppEligibilityService::class),
            app(EntidadeQuotaService::class),
        );

        $this->assertSame(WhatsAppNotificationStatus::Submitted, $notification->fresh()->status);
    }

    public function test_sensitive_data_purge_preserves_hashes_and_audit_state(): void
    {
        [, $user, $contact] = $this->eligibleContext(WhatsAppPurpose::DemandAssigned);
        $notification = app(WhatsAppOutboxService::class)->enqueue(
            $contact,
            WhatsAppPurpose::DemandAssigned,
            'demand:40:assigned:50',
            ['Pessoa Teste', 'GAB-2026-0040', '05/08/2026'],
        );
        $this->assertNotNull($notification);
        $notification->forceFill(['expurgar_sensiveis_em' => now()->subMinute()])->save();
        app(WhatsAppContactService::class)->revoke($contact, $user);
        $contact->forceFill(['revogado_em' => now()->subDays(91)])->save();

        $this->assertSame(2, app(WhatsAppSensitiveDataPurger::class)->purge());

        $notification->refresh();
        $contact->refresh();
        $this->assertNull($notification->telefone_criptografado);
        $this->assertNull($notification->parametros_corpo_criptografados);
        $this->assertNotNull($notification->telefone_hash);
        $this->assertSame(WhatsAppNotificationStatus::Pending, $notification->status);
        $this->assertNull($contact->telefone_criptografado);
        $this->assertNotNull($contact->telefone_hash);
        $this->assertSame(WhatsAppContactStatus::Revoked, $contact->status);
    }

    public function test_admin_page_is_restricted_to_platform_administrators(): void
    {
        $this->withoutVite();
        $office = Gabinete::factory()->create();
        $user = User::factory()->forGabinete($office)->create();
        $admin = User::factory()->root()->create();

        $this->actingAs($user)->get(route('admin.whatsapp.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.whatsapp.index'))->assertOk();
    }

    public function test_readiness_requires_exactly_one_team_member_and_one_citizen_in_pilot(): void
    {
        [$office, $user, $userContact] = $this->contactContext();
        $citizen = Cidadao::factory()->forGabinete($office)->create();
        $citizenContact = app(WhatsAppContactService::class)
            ->declareForCitizen($citizen, '(88) 98888-4321', $user);
        $userContact->forceFill(['piloto' => true])->save();
        $citizenContact->forceFill(['piloto' => true])->save();
        $this->entidadeWhatsAppConnection($office);
        app(WhatsAppConfigurationService::class)->update(
            $office,
            WhatsAppMode::Pilot,
            '08:00',
            array_column(WhatsAppPurpose::operationalUtilityCases(), 'value'),
        );
        foreach (WhatsAppPurpose::cases() as $purpose) {
            $this->readyEntidadeWhatsAppTemplate($office, $purpose);
        }

        $this->artisan('govnexgab:whatsapp-audit --strict')->assertSuccessful();

        $extra = User::factory()->forGabinete($office)->create();
        $extraContact = app(WhatsAppContactService::class)
            ->declareForUser($extra, '(88) 97777-5678', $extra);
        $extraContact->forceFill(['piloto' => true])->save();

        $this->artisan('govnexgab:whatsapp-audit --strict')->assertFailed();
    }

    /** @return array{Gabinete,User,WhatsAppContact} */
    private function contactContext(): array
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->forGabinete($office)->create();
        $contact = app(WhatsAppContactService::class)
            ->declareForUser($user, '(88) 99999-1234', $user);

        return [$office, $user, $contact];
    }

    /** @return array{Gabinete,User,WhatsAppContact} */
    private function eligibleContext(WhatsAppPurpose $purpose): array
    {
        [$office, $user, $contact] = $this->contactContext();
        $this->entidadeWhatsAppConnection($office);
        app(WhatsAppConfigurationService::class)->update(
            $office,
            WhatsAppMode::Live,
            '08:00',
            [$purpose->value],
        );
        $this->readyEntidadeWhatsAppTemplate($office, $purpose);

        return [$office, $user, $contact];
    }

    /** @return array<string, mixed> */
    private function inboundPayload(): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'INBOUND_MESSAGE',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'phone_hash' => str_repeat('a', 64),
                'message_type' => 'TEXT',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function statusPayload(WhatsAppNotification $notification, string $status): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'MESSAGE_STATUS',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'client_request_id' => $notification->client_request_id,
                'status' => $status,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function postSignedCallback(
        array $payload,
        string $secret = self::CALLBACK_SECRET,
        ?int $timestamp = null,
        ?string $nonce = null,
    ): TestResponse {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp ??= now()->timestamp;
        $nonce ??= 'nonce-'.Str::random(32);
        $path = '/api/integrations/whatsapp/callback';
        $canonical = "POST\n{$path}\n{$timestamp}\n{$nonce}\n".hash('sha256', $body);
        $signature = 'sha256='.hash_hmac('sha256', $canonical, $secret);

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WHATSAPP_CLIENT' => 'GABINETE',
            'HTTP_X_WHATSAPP_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_WHATSAPP_NONCE' => $nonce,
            'HTTP_X_WHATSAPP_SIGNATURE' => $signature,
        ], $body);
    }
}

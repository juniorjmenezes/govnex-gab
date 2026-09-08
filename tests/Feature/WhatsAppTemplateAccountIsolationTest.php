<?php

namespace Tests\Feature;

use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppPurpose;
use App\Models\Gabinete;
use App\Models\User;
use App\Models\WhatsAppTemplatePurpose;
use App\Services\WhatsApp\WhatsAppConfigurationService;
use App\Services\WhatsApp\WhatsAppTemplateCatalog;
use App\Services\WhatsApp\WhatsAppTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WhatsAppTemplateAccountIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'whatsapp.gateway_url' => 'https://whatsapp.example.test',
            'whatsapp.client_code' => 'GABINETE',
            'whatsapp.request_secret' => str_repeat('r', 40),
            'whatsapp.gateway_account_id' => 6,
        ]);
    }

    public function test_sync_uses_only_latest_template_from_configured_account(): void
    {
        $purpose = WhatsAppPurpose::DemandAssigned;
        $definition = app(WhatsAppTemplateCatalog::class)->definition($purpose);
        app(WhatsAppTemplateService::class)->catalog();
        $local = WhatsAppTemplatePurpose::query()->where('finalidade', $purpose->value)->firstOrFail();
        $local->forceFill([
            'gateway_template_id' => 44,
            'status' => 'APPROVED',
            'ativo' => true,
        ])->save();
        Http::fake(['*' => Http::response(['templates' => [
            $this->remote($purpose, $definition, accountId: 4, id: 90, version: 9, status: 'APPROVED'),
            $this->remote($purpose, $definition, accountId: 6, id: 101, version: 1, status: 'DRAFT'),
            $this->remote($purpose, $definition, accountId: 6, id: 102, version: 2, status: 'PENDING'),
        ]])]);

        app(WhatsAppTemplateService::class)->sync();

        $local->refresh();
        $this->assertSame(102, $local->gateway_template_id);
        $this->assertSame('PENDING', $local->status);
        $this->assertFalse($local->ativo);
    }

    public function test_sync_marks_purpose_absent_when_only_another_account_has_it(): void
    {
        $purpose = WhatsAppPurpose::DemandAssigned;
        $definition = app(WhatsAppTemplateCatalog::class)->definition($purpose);
        app(WhatsAppTemplateService::class)->catalog();
        Http::fake(['*' => Http::response(['templates' => [
            $this->remote($purpose, $definition, accountId: 4, id: 90, version: 9, status: 'APPROVED'),
        ]])]);

        app(WhatsAppTemplateService::class)->sync();

        $local = WhatsAppTemplatePurpose::query()->where('finalidade', $purpose->value)->firstOrFail();
        $this->assertNull($local->gateway_template_id);
        $this->assertSame('ABSENT', $local->status);
        $this->assertFalse($local->ativo);
    }

    public function test_replacement_adopts_matching_target_draft_without_duplicate_post(): void
    {
        $purpose = WhatsAppPurpose::DemandAssigned;
        $definition = app(WhatsAppTemplateCatalog::class)->definition($purpose);
        $local = $this->local($purpose, $definition, 44, 'APPROVED');
        Http::fake(['*' => Http::response(['templates' => [
            $this->remote($purpose, $definition, accountId: 6, id: 101, version: 2, status: 'DRAFT'),
        ]])]);

        $result = app(WhatsAppTemplateService::class)->createReplacementDraft($local, null, 1);

        $this->assertSame(101, $result->gateway_template_id);
        $this->assertSame('DRAFT', $result->status);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public function test_replacement_creation_sends_configured_account_id(): void
    {
        $purpose = WhatsAppPurpose::DemandAssigned;
        $definition = app(WhatsAppTemplateCatalog::class)->definition($purpose);
        $local = $this->local($purpose, $definition, 44, 'APPROVED');
        Http::fake(function (Request $request) use ($purpose, $definition) {
            if ($request->method() === 'GET') {
                return Http::response(['templates' => []]);
            }

            $this->assertSame(6, $request->data()['account_id'] ?? null);

            return Http::response(['template' => $this->remote(
                $purpose,
                $definition,
                accountId: 6,
                id: 102,
                version: 2,
                status: 'DRAFT',
            )], 201);
        });

        $result = app(WhatsAppTemplateService::class)->createReplacementDraft($local, null, 1);

        $this->assertSame(102, $result->gateway_template_id);
        Http::assertSentCount(2);
    }

    public function test_recreation_command_is_dry_run_and_excludes_marketing_purpose(): void
    {
        User::factory()->root()->create();
        app(WhatsAppTemplateService::class)->catalog();
        Http::fake();

        $this->artisan('govnexgab:whatsapp-recreate-templates')->assertSuccessful();
        Http::assertNothingSent();

        $this->artisan('govnexgab:whatsapp-recreate-templates', [
            '--purpose' => [WhatsAppPurpose::PoliticalPollPublished->value],
            '--confirm' => true,
        ])->expectsOutputToContain('política de marketing')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_marketing_purpose_cannot_be_enabled_or_created_as_utility(): void
    {
        $purpose = WhatsAppPurpose::PoliticalPollPublished;
        $office = Gabinete::factory()->create();
        Http::fake();

        try {
            app(WhatsAppConfigurationService::class)->update(
                $office,
                WhatsAppMode::Pilot,
                '08:00',
                [$purpose->value],
            );
            $this->fail('A finalidade de marketing não deveria ser habilitada pelo consentimento operacional.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('finalidades', $exception->getMessage());
        }

        try {
            app(WhatsAppTemplateService::class)->createDraft($purpose, null, 1);
            $this->fail('A finalidade de marketing não deveria criar template utilitário.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('política de marketing', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_template_operations_fail_closed_without_configured_account(): void
    {
        config()->set('whatsapp.gateway_account_id', 0);
        Http::fake();
        $service = app(WhatsAppTemplateService::class);

        foreach (['sync', 'create'] as $operation) {
            try {
                if ($operation === 'sync') {
                    $service->sync();
                } else {
                    $service->createDraft(WhatsAppPurpose::DemandAssigned, null, 1);
                }
                $this->fail('A operação de template não deveria continuar sem uma conta configurada.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('conta de destino', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $definition */
    private function local(
        WhatsAppPurpose $purpose,
        array $definition,
        int $gatewayId,
        string $status,
    ): WhatsAppTemplatePurpose {
        return WhatsAppTemplatePurpose::query()->create([
            'finalidade' => $purpose->value,
            'gateway_template_id' => $gatewayId,
            'nome_meta' => $definition['meta_name'],
            'idioma' => 'pt_BR',
            'categoria' => 'UTILITY',
            'status' => $status,
            'componentes' => $definition['components'],
            'contrato_hash' => $definition['contract_hash'],
            'ativo' => false,
        ]);
    }

    /** @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function remote(
        WhatsAppPurpose $purpose,
        array $definition,
        int $accountId,
        int $id,
        int $version,
        string $status,
    ): array {
        return [
            'id' => $id,
            'account_id' => $accountId,
            'purpose' => $purpose->value,
            'name' => $definition['meta_name'],
            'language' => 'pt_BR',
            'category' => 'UTILITY',
            'version' => $version,
            'status' => $status,
            'components' => $definition['components'],
            'archived_at' => null,
        ];
    }
}

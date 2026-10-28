<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\EntityQuota;
use App\Enums\EntityWhatsAppConnectionType;
use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppNotificationStatus;
use App\Enums\WhatsAppPurpose;
use App\Jobs\SendWhatsAppNotification;
use App\Models\EntityLicense;
use App\Models\EntityUsage;
use App\Models\Office;
use App\Models\User;
use App\Models\WhatsAppConfiguration;
use App\Models\WhatsAppContact;
use App\Services\Entidades\EntityQuotaService;
use App\Services\WhatsApp\EntityWhatsAppConnectionService;
use App\Services\WhatsApp\WhatsAppConfigurationService;
use App\Services\WhatsApp\WhatsAppContactService;
use App\Services\WhatsApp\WhatsAppEligibilityService;
use App\Services\WhatsApp\WhatsAppGatewayClient;
use App\Services\WhatsApp\WhatsAppOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ProvisionsEntidadeWhatsApp;
use Tests\TestCase;

class EntidadeWhatsAppIsolationTest extends TestCase
{
    use ProvisionsEntidadeWhatsApp, RefreshDatabase;

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
            'whatsapp.request_secret' => str_repeat('r', 40),
            'whatsapp.callback_secret' => str_repeat('c', 40),
            'whatsapp.phone_hash_secret' => str_repeat('h', 40),
        ]);
    }

    public function test_non_off_mode_requires_a_persisted_entidade_connection(): void
    {
        $office = Office::factory()->create();

        $this->expectException(ValidationException::class);
        app(WhatsAppConfigurationService::class)->update(
            $office,
            WhatsAppMode::Live,
            '08:00',
            [WhatsAppPurpose::DemandAssigned->value],
        );
    }

    public function test_an_entidade_cannot_use_another_entidades_template(): void
    {
        [$firstOffice, , $contact] = $this->contactContext();
        $secondOffice = Office::factory()->create();
        $this->entidadeWhatsAppConnection($firstOffice, 6);
        $this->readyEntidadeWhatsAppTemplate($secondOffice, WhatsAppPurpose::DemandAssigned);
        app(WhatsAppConfigurationService::class)->update(
            $firstOffice,
            WhatsAppMode::Live,
            '08:00',
            [WhatsAppPurpose::DemandAssigned->value],
        );

        $result = app(WhatsAppEligibilityService::class)->evaluate(
            $contact,
            WhatsAppPurpose::DemandAssigned,
        );

        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('template aprovado', $result['reason']);
    }

    public function test_central_accounts_can_be_explicitly_shared_but_own_accounts_cannot(): void
    {
        $firstOffice = Office::factory()->create();
        $secondOffice = Office::factory()->create();
        $admin = User::factory()->root()->create();
        Http::fake(['*' => Http::response(['accounts' => [
            $this->remoteAccount(21),
            $this->remoteAccount(22),
        ]])]);
        $service = app(EntityWhatsAppConnectionService::class);

        $firstCentral = $service->assign(
            $firstOffice->entidade()->firstOrFail(),
            21,
            EntityWhatsAppConnectionType::Central,
            $admin,
        );
        $secondCentral = $service->assign(
            $secondOffice->entidade()->firstOrFail(),
            21,
            EntityWhatsAppConnectionType::Central,
            $admin,
        );

        $this->assertTrue($firstCentral->ativo);
        $this->assertTrue($secondCentral->ativo);

        $service->assign(
            $firstOffice->entidade()->firstOrFail(),
            22,
            EntityWhatsAppConnectionType::Own,
            $admin,
        );
        try {
            $service->assign(
                $secondOffice->entidade()->firstOrFail(),
                22,
                EntityWhatsAppConnectionType::Own,
                $admin,
            );
            $this->fail('Uma conta própria não deveria ser compartilhada.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('conta própria', (string) collect($exception->errors())->flatten()->first());
        }
    }

    public function test_switching_accounts_turns_off_configurations_and_disables_old_templates(): void
    {
        [$office] = $this->contactContext();
        $oldConnection = $this->entidadeWhatsAppConnection($office, 6);
        $oldTemplate = $this->readyEntidadeWhatsAppTemplate($office, WhatsAppPurpose::DemandAssigned);
        app(WhatsAppConfigurationService::class)->update(
            $office,
            WhatsAppMode::Live,
            '08:00',
            [WhatsAppPurpose::DemandAssigned->value],
        );
        Http::fake(['*' => Http::response(['accounts' => [$this->remoteAccount(7)]])]);

        $newConnection = app(EntityWhatsAppConnectionService::class)->assign(
            $office->entidade()->firstOrFail(),
            7,
            EntityWhatsAppConnectionType::Central,
            User::factory()->root()->create(),
        );

        $configuration = WhatsAppConfiguration::withoutGlobalScopes()
            ->where('gabinete_id', $office->id)
            ->firstOrFail();
        $this->assertSame(WhatsAppMode::Off, $configuration->modo);
        $this->assertSame($newConnection->id, $configuration->entidade_whatsapp_conexao_id);
        $this->assertFalse($oldConnection->fresh()->ativo);
        $this->assertFalse($oldTemplate->fresh()->ativo);
    }

    public function test_monthly_quota_is_reserved_once_across_queued_notifications(): void
    {
        [$office, , $contact] = $this->eligibleContext();
        $license = EntityLicense::query()->where('entidade_id', $office->entidade_id)->firstOrFail();
        $quotas = $license->cotas_snapshot;
        $quotas[EntityQuota::MonthlyWhatsAppMessages->value] = 1;
        $license->forceFill(['cotas_snapshot' => $quotas])->save();
        $outbox = app(WhatsAppOutboxService::class);
        $first = $outbox->enqueue($contact, WhatsAppPurpose::DemandAssigned, 'quota-first', ['Pessoa', 'DEM-1', 'Hoje']);
        $second = $outbox->enqueue($contact, WhatsAppPurpose::DemandAssigned, 'quota-second', ['Pessoa', 'DEM-2', 'Hoje']);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        Http::fake(['*' => Http::response(['status' => 'QUEUED'])]);

        $this->runSendJob($first->id);
        $this->runSendJob($second->id);

        $this->assertSame(WhatsAppNotificationStatus::Submitted, $first->fresh()->status);
        $this->assertNotNull($first->fresh()->consumo_registrado_em);
        $this->assertSame(WhatsAppNotificationStatus::Suppressed, $second->fresh()->status);
        $this->assertNull($second->fresh()->consumo_reservado_em);
        $this->assertSame(1, app(EntityQuotaService::class)->usage(
            $office->entidade_id,
            EntityQuota::MonthlyWhatsAppMessages,
        ));
        Http::assertSentCount(1);
    }

    public function test_definitive_rejection_releases_the_reserved_quota(): void
    {
        [$office, , $contact] = $this->eligibleContext();
        $notification = app(WhatsAppOutboxService::class)->enqueue(
            $contact,
            WhatsAppPurpose::DemandAssigned,
            'quota-rejected',
            ['Pessoa', 'DEM-3', 'Hoje'],
        );
        $this->assertNotNull($notification);
        Http::fake(fn (Request $request) => Http::response([
            'message' => 'Template recusado.',
            'retryable' => false,
        ], 422));

        $this->runSendJob($notification->id);

        $notification->refresh();
        $this->assertSame(WhatsAppNotificationStatus::Failed, $notification->status);
        $this->assertNull($notification->consumo_reservado_em);
        $this->assertNull($notification->consumo_registrado_em);
        $this->assertSame(0, (int) EntityUsage::query()
            ->where('entidade_id', $office->entidade_id)
            ->where('metrica', EntityQuota::MonthlyWhatsAppMessages)
            ->value('quantidade'));
    }

    /** @return array{Office, User, WhatsAppContact} */
    private function contactContext(): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forGabinete($office)->create();
        $contact = app(WhatsAppContactService::class)->declareForUser(
            $user,
            '(88) 99999-1234',
            $user,
        );

        return [$office, $user, $contact];
    }

    /** @return array{Office, User, WhatsAppContact} */
    private function eligibleContext(): array
    {
        [$office, $user, $contact] = $this->contactContext();
        $this->entidadeWhatsAppConnection($office);
        $this->readyEntidadeWhatsAppTemplate($office, WhatsAppPurpose::DemandAssigned);
        app(WhatsAppConfigurationService::class)->update(
            $office,
            WhatsAppMode::Live,
            '08:00',
            [WhatsAppPurpose::DemandAssigned->value],
        );

        return [$office, $user, $contact];
    }

    /** @return array{id:int,name:string,phone_last_four:string,active:bool,status:string} */
    private function remoteAccount(int $id): array
    {
        return [
            'id' => $id,
            'name' => 'Conta '.$id,
            'phone_last_four' => str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'active' => true,
            'status' => 'ACTIVE',
        ];
    }

    private function runSendJob(int $notificationId): void
    {
        (new SendWhatsAppNotification($notificationId))->handle(
            app(WhatsAppGatewayClient::class),
            app(WhatsAppEligibilityService::class),
            app(EntityQuotaService::class),
        );
    }
}

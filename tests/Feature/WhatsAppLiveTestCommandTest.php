<?php

namespace Tests\Feature;

use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppNotificationStatus;
use App\Enums\WhatsAppPurpose;
use App\Models\Categoria;
use App\Models\Cidadao;
use App\Models\Gabinete;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Services\WhatsApp\WhatsAppConfigurationService;
use App\Services\WhatsApp\WhatsAppContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\ProvisionsEntidadeWhatsApp;
use Tests\TestCase;

final class WhatsAppLiveTestCommandTest extends TestCase
{
    use ProvisionsEntidadeWhatsApp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set([
            'whatsapp.driver' => 'gateway',
            'whatsapp.real_enabled' => true,
            'whatsapp.gateway_url' => 'https://whatsapp.example.test',
            'whatsapp.client_code' => 'GABINETE',
            'whatsapp.request_secret' => str_repeat('r', 32),
            'whatsapp.callback_secret' => str_repeat('c', 32),
            'whatsapp.phone_hash_secret' => str_repeat('h', 32),
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'status' => 'QUEUED'])]);
    }

    public function test_dry_run_lists_the_scenario_without_writing_data_or_manifest(): void
    {
        [$office] = $this->readyContext();
        $runId = (string) Str::uuid();

        $this->artisan('govnexgab:whatsapp-live-test', [
            '--office' => $office->slug,
            '--run-id' => $runId,
        ])->assertSuccessful();

        $this->assertDatabaseCount('demandas', 0);
        $this->assertDatabaseCount('compromissos', 0);
        $this->assertDatabaseCount('whatsapp_notificacoes', 0);
        Storage::disk('local')->assertMissing('whatsapp-live-tests/'.$runId.'.json');
    }

    public function test_confirmed_run_creates_exactly_40_idempotent_notifications_and_a_sanitized_manifest(): void
    {
        [$office, $teamContacts, $citizenContacts, $phones] = $this->readyContext();
        $runId = (string) Str::uuid();
        $arguments = [
            '--office' => $office->slug,
            '--run-id' => $runId,
            '--team-contact' => $teamContacts,
            '--citizen-contact' => $citizenContacts,
            '--expected-messages' => 40,
            '--wait-seconds' => 0,
            '--confirm-live' => true,
        ];

        $this->artisan('govnexgab:whatsapp-live-test', $arguments)->assertSuccessful();

        $this->assertDatabaseCount('demandas', 3);
        $this->assertDatabaseCount('compromissos', 3);
        $this->assertDatabaseCount('whatsapp_notificacoes', 40);
        $this->assertPurposeCount(WhatsAppPurpose::DemandAssigned, 3);
        $this->assertPurposeCount(WhatsAppPurpose::DemandStatusChanged, 6);
        $this->assertPurposeCount(WhatsAppPurpose::DemandObservationAdded, 2);
        $this->assertPurposeCount(WhatsAppPurpose::DemandDeadlineDigest, 2);
        $this->assertPurposeCount(WhatsAppPurpose::AppointmentStaffReminder, 6);
        $this->assertPurposeCount(WhatsAppPurpose::AppointmentCitizenReminder, 3);
        $this->assertPurposeCount(WhatsAppPurpose::AppointmentChanged, 9);
        $this->assertPurposeCount(WhatsAppPurpose::AppointmentCancelled, 9);

        $path = 'whatsapp-live-tests/'.$runId.'.json';
        Storage::disk('local')->assertExists($path);
        $manifest = (string) Storage::disk('local')->get($path);
        foreach ($phones as $phone) {
            $this->assertStringNotContainsString($phone, $manifest);
        }
        $this->assertStringNotContainsString('telefone_criptografado', $manifest);
        $this->assertStringNotContainsString('parametros_corpo_criptografados', $manifest);

        $this->artisan('govnexgab:whatsapp-live-test', $arguments)->assertSuccessful();
        $this->assertDatabaseCount('demandas', 3);
        $this->assertDatabaseCount('compromissos', 3);
        $this->assertDatabaseCount('whatsapp_notificacoes', 40);

        WhatsAppNotification::query()->update([
            'status' => WhatsAppNotificationStatus::Sent->value,
            'enviado_em' => now(),
        ]);
        $this->artisan('govnexgab:whatsapp-live-test', [
            '--office' => $office->slug,
            '--run-id' => $runId,
            '--stage' => 'report',
            '--expected-messages' => 40,
            '--wait-seconds' => 0,
            '--confirm-live' => true,
        ])->assertSuccessful();

        $decoded = json_decode((string) Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('COMPLETED', $decoded['status']);
        $this->assertCount(40, $decoded['notification_ids']);

        $this->artisan('govnexgab:whatsapp-live-test', $arguments)->assertSuccessful();
        $decoded = json_decode((string) Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('COMPLETED', $decoded['status']);
        $this->assertDatabaseCount('whatsapp_notificacoes', 40);
    }

    public function test_batches_can_be_executed_separately_without_requiring_40_messages_early(): void
    {
        [$office, $teamContacts, $citizenContacts] = $this->readyContext();
        $runId = (string) Str::uuid();

        $this->artisan('govnexgab:whatsapp-live-test', [
            '--office' => $office->slug,
            '--run-id' => $runId,
            '--team-contact' => $teamContacts,
            '--citizen-contact' => $citizenContacts,
            '--stage' => 'demands',
            '--expected-messages' => 40,
            '--wait-seconds' => 0,
            '--confirm-live' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('whatsapp_notificacoes', 11);
        $manifest = json_decode((string) Storage::disk('local')->get(
            'whatsapp-live-tests/'.$runId.'.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('PARTIAL', $manifest['status']);
        $this->assertSame('COMPLETED', $manifest['stages']['demands']['status']);
        $this->assertCount(11, $manifest['notification_ids']);
    }

    public function test_definitive_failure_stops_before_status_digest_and_agenda_batches(): void
    {
        [$office, $teamContacts, $citizenContacts] = $this->readyContext();
        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response(['message' => 'Template recusado.'], 400)]);
        $runId = (string) Str::uuid();

        $this->artisan('govnexgab:whatsapp-live-test', [
            '--office' => $office->slug,
            '--run-id' => $runId,
            '--team-contact' => $teamContacts,
            '--citizen-contact' => $citizenContacts,
            '--expected-messages' => 40,
            '--wait-seconds' => 0,
            '--confirm-live' => true,
        ])->assertFailed();

        $this->assertDatabaseCount('demandas', 3);
        $this->assertDatabaseCount('compromissos', 0);
        $this->assertDatabaseCount('whatsapp_notificacoes', 3);
        $this->assertSame(3, WhatsAppNotification::query()
            ->where('finalidade', WhatsAppPurpose::DemandAssigned->value)
            ->where('status', WhatsAppNotificationStatus::Failed->value)
            ->count());

        $manifest = json_decode((string) Storage::disk('local')->get(
            'whatsapp-live-tests/'.$runId.'.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('FAILED', $manifest['status']);
        $this->assertArrayNotHasKey('digest', $manifest['stages']);
        $this->assertArrayNotHasKey('agenda-reminders', $manifest['stages']);
    }

    /** @return array{Gabinete, list<int>, list<int>, list<string>} */
    private function readyContext(): array
    {
        $office = Gabinete::factory()->create([
            'nome' => 'Gabinete Fortaleza',
            'slug' => 'fortaleza',
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
            'timezone' => 'America/Fortaleza',
        ]);
        Categoria::factory()->for($office)->create(['ativo' => true]);
        $team = [
            User::factory()->administrator()->forGabinete($office)->create(['name' => 'Marina Oliveira']),
            User::factory()->administrator()->forGabinete($office)->create(['name' => 'Chefe de Gabinete']),
        ];
        $citizens = [
            Cidadao::factory()->forGabinete($office)->create(['nome' => 'Ana Martins']),
            Cidadao::factory()->forGabinete($office)->create(['nome' => 'Carlos Lima']),
            Cidadao::factory()->forGabinete($office)->create(['nome' => 'Joana Alves']),
        ];
        $phones = [
            '5588999991101',
            '5588999991102',
            '5588999991103',
            '5588999991104',
            '5588999991105',
        ];
        $contacts = app(WhatsAppContactService::class);
        $teamContacts = [
            $contacts->declareForUser($team[0], $phones[0], $team[0])->id,
            $contacts->declareForUser($team[1], $phones[1], $team[1])->id,
        ];
        $citizenContacts = [
            $contacts->declareForCitizen($citizens[0], $phones[2], $team[0])->id,
            $contacts->declareForCitizen($citizens[1], $phones[3], $team[0])->id,
            $contacts->declareForCitizen($citizens[2], $phones[4], $team[0])->id,
        ];
        $purposes = WhatsAppPurpose::operationalUtilityCases();
        $this->entidadeWhatsAppConnection($office);
        app(WhatsAppConfigurationService::class)->update(
            $office,
            WhatsAppMode::Live,
            '08:00',
            array_map(fn (WhatsAppPurpose $purpose): string => $purpose->value, $purposes),
        );
        foreach ($purposes as $purpose) {
            $this->readyEntidadeWhatsAppTemplate($office, $purpose);
        }

        return [$office, $teamContacts, $citizenContacts, $phones];
    }

    private function assertPurposeCount(WhatsAppPurpose $purpose, int $expected): void
    {
        $this->assertSame($expected, WhatsAppNotification::query()
            ->where('finalidade', $purpose->value)
            ->count());
    }
}

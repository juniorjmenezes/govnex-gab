<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\AccessRole;
use App\Enums\GabineteModule;
use App\Enums\GabineteTransferStatus;
use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppNotificationStatus;
use App\Enums\WhatsAppPurpose;
use App\Models\Demanda;
use App\Models\Entidade;
use App\Models\Gabinete;
use App\Models\User;
use App\Models\WhatsAppConfiguration;
use App\Models\WhatsAppNotification;
use App\Services\Entidades\GabineteTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GabineteTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        Storage::fake('local');
    }

    public function test_transfer_requires_same_municipality_and_state(): void
    {
        [$source, $gabinete, $sourceManager, $destination] = $this->context();
        $destination->forceFill(['municipio' => 'Sobral'])->save();

        $this->expectException(ValidationException::class);
        app(GabineteTransferService::class)->request(
            $source,
            $gabinete,
            $destination,
            $sourceManager,
        );
    }

    public function test_transfer_requires_source_destination_and_platform_approval(): void
    {
        [$source, $gabinete, $sourceManager, $destination, $destinationManager, $admin] = $this->context();
        $service = app(GabineteTransferService::class);

        $transfer = $service->request($source, $gabinete, $destination, $sourceManager);
        $this->assertSame(GabineteTransferStatus::PendingDestination, $transfer->status);

        $transfer = $service->acceptDestination($transfer, $destinationManager);
        $this->assertSame(GabineteTransferStatus::PendingPlatform, $transfer->status);

        $transfer = $service->approve($transfer, $admin);
        $this->assertSame(GabineteTransferStatus::Completed, $transfer->status);
        $this->assertSame($destination->id, $gabinete->fresh()->entidade_id);
        $this->assertNotNull($transfer->manifesto_hash);
        $this->assertDatabaseCount('gabinete_transferencia_eventos', 3);
    }

    public function test_private_unit_data_memberships_modules_and_whatsapp_follow_transfer_rules(): void
    {
        [$source, $gabinete, $sourceManager, $destination, $destinationManager, $admin] = $this->context();
        $member = User::factory()->operator()->forGabinete($gabinete)->create();
        $demand = Demanda::factory()->forGabinete($gabinete, creator: $member)->create();

        DB::table('entidade_modulos')
            ->where('entidade_id', $destination->id)
            ->where('modulo', GabineteModule::Demands->value)
            ->update(['contratado' => false, 'ativo' => false, 'updated_at' => now()]);
        (new WhatsAppConfiguration)->forceFill([
            'entidade_id' => $source->id,
            'gabinete_id' => $gabinete->id,
            'modo' => WhatsAppMode::Live,
            'resumo_diario_em' => '08:00',
            'finalidades_habilitadas' => [],
        ])->save();

        $transfer = app(GabineteTransferService::class)->request($source, $gabinete, $destination, $sourceManager);
        app(GabineteTransferService::class)->acceptDestination($transfer, $destinationManager);
        app(GabineteTransferService::class)->approve($transfer, $admin);

        $this->assertDatabaseHas('demandas', [
            'id' => $demand->id,
            'gabinete_id' => $gabinete->id,
        ]);
        $this->assertDatabaseHas('entidade_membros', [
            'entidade_id' => $destination->id,
            'usuario_id' => $member->id,
            'papel' => AccessRole::Operator->value,
            'ativo' => true,
        ]);
        $this->assertDatabaseHas('entidade_membros', [
            'entidade_id' => $source->id,
            'usuario_id' => $member->id,
            'ativo' => false,
        ]);
        $this->assertDatabaseHas('entidade_membros', [
            'entidade_id' => $source->id,
            'usuario_id' => $sourceManager->id,
            'papel' => AccessRole::Administrator->value,
            'ativo' => true,
        ]);
        $this->assertDatabaseHas('gabinete_modulos', [
            'gabinete_id' => $gabinete->id,
            'modulo' => GabineteModule::Demands->value,
            'ativo' => false,
        ]);
        $this->assertDatabaseHas('whatsapp_configuracoes', [
            'gabinete_id' => $gabinete->id,
            'entidade_id' => $destination->id,
            'entidade_whatsapp_conexao_id' => null,
            'modo' => WhatsAppMode::Off->value,
        ]);
    }

    public function test_open_whatsapp_delivery_blocks_platform_approval(): void
    {
        [$source, $gabinete, $sourceManager, $destination, $destinationManager, $admin] = $this->context();
        $transfer = app(GabineteTransferService::class)->request($source, $gabinete, $destination, $sourceManager);
        app(GabineteTransferService::class)->acceptDestination($transfer, $destinationManager);
        (new WhatsAppNotification)->forceFill([
            'entidade_id' => $source->id,
            'gabinete_id' => $gabinete->id,
            'client_request_id' => (string) Str::uuid(),
            'idempotency_key' => hash('sha256', Str::random()),
            'finalidade' => WhatsAppPurpose::DemandAssigned,
            'telefone_hash' => hash('sha256', '5588999999999'),
            'telefone_final' => '9999',
            'status' => WhatsAppNotificationStatus::Processing,
            'tentativas' => 0,
            'expira_em' => now()->addHour(),
        ])->save();

        try {
            app(GabineteTransferService::class)->approve($transfer, $admin);
            $this->fail('A transferência deveria aguardar o envio WhatsApp.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('whatsapp', $exception->errors());
        }

        $this->assertSame(GabineteTransferStatus::PendingPlatform, $transfer->fresh()->status);
        $this->assertSame($source->id, $gabinete->fresh()->entidade_id);
    }

    public function test_transfer_is_hidden_from_unrelated_entidades(): void
    {
        [$source, $gabinete, $sourceManager, $destination, $destinationManager, $admin] = $this->context();
        User::factory()->operator()->forGabinete($gabinete)->create();
        $transfer = app(GabineteTransferService::class)->request($source, $gabinete, $destination, $sourceManager);
        app(GabineteTransferService::class)->acceptDestination($transfer, $destinationManager);
        $completed = app(GabineteTransferService::class)->approve($transfer, $admin);

        $unrelatedUnit = Gabinete::factory()->create();
        $unrelatedManager = User::factory()->administrator()->forGabinete($unrelatedUnit)->create();
        $this->actingAs($unrelatedManager)
            ->get(route('entidades.transfers.show', [
                'entidade' => $destination,
                'transfer' => $completed,
            ]))
            ->assertForbidden();
    }

    public function test_legacy_source_context_no_longer_resolves_the_transferred_unit(): void
    {
        [$source, $gabinete, $sourceManager, $destination, $destinationManager, $admin] = $this->context();
        $transfer = app(GabineteTransferService::class)->request($source, $gabinete, $destination, $sourceManager);
        app(GabineteTransferService::class)->acceptDestination($transfer, $destinationManager);
        app(GabineteTransferService::class)->approve($transfer, $admin);

        $this->actingAs($sourceManager)
            ->get(route('context.dashboard', [
                'entidade' => $source,
                'gabinete' => $gabinete,
            ]))
            ->assertNotFound();
    }

    /**
     * @return array{Entidade,Gabinete,User,Entidade,User,User}
     */
    private function context(): array
    {
        $gabinete = Gabinete::factory()->create(['municipio' => 'Fortaleza', 'estado' => 'CE']);
        $source = $gabinete->entidade;
        $source->forceFill(['municipio' => 'Fortaleza', 'estado' => 'CE'])->save();
        $sourceManager = User::factory()->administrator()->forGabinete($gabinete)->create();

        $destinationUnit = Gabinete::factory()->create(['municipio' => 'Fortaleza', 'estado' => 'CE']);
        $destination = $destinationUnit->entidade;
        $destination->forceFill(['municipio' => 'Fortaleza', 'estado' => 'CE'])->save();
        $destinationManager = User::factory()->administrator()->forGabinete($destinationUnit)->create();

        return [
            $source,
            $gabinete,
            $sourceManager,
            $destination,
            $destinationManager,
            User::factory()->root()->create(),
        ];
    }
}

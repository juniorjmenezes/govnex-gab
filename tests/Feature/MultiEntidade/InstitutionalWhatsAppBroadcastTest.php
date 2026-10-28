<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\AccessRole;
use App\Enums\AppointmentStatus;
use App\Enums\EntityModule;
use App\Enums\EntityType;
use App\Enums\OfficeModule;
use App\Enums\OfficeType;
use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppPurpose;
use App\Models\Citizen;
use App\Models\CompromissoInstitucional;
use App\Models\Entity;
use App\Models\EntityModuleSubscription;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\OfficeModuleSubscription;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppNotification;
use App\Services\WhatsApp\WhatsAppConfigurationService;
use App\Services\WhatsApp\WhatsAppContactService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ProvisionsEntidadeWhatsApp;
use Tests\TestCase;

class InstitutionalWhatsAppBroadcastTest extends TestCase
{
    use ProvisionsEntidadeWhatsApp, RefreshDatabase;

    private const TIMEZONE = 'America/Sao_Paulo';

    private const DAY = '2026-11-10';

    private const PURPOSE = WhatsAppPurpose::InstitutionalConvocation;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set([
            'whatsapp.driver' => 'gateway',
            'whatsapp.real_enabled' => true,
            'whatsapp.gateway_url' => 'https://whatsapp.example.test',
            'whatsapp.client_code' => 'GABINETE',
            'whatsapp.request_secret' => str_repeat('r', 32),
            'whatsapp.callback_secret' => str_repeat('c', 32),
            'whatsapp.phone_hash_secret' => str_repeat('h', 32),
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00', self::TIMEZONE));
    }

    public function test_open_audience_reaches_staff_of_every_councilor_office_of_the_city_council_only(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $vereadorA = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $vereadorB = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $assessorA = $this->staff($vereadorA, '(88) 99999-0001');
        $assessorB = $this->staff($vereadorB, '(88) 99999-0002');
        // Cidadão com consentimento no gabinete de vereador: não é convocado.
        $cidadao = Citizen::factory()->forGabinete($vereadorA)->create();
        app(WhatsAppContactService::class)->declareForCitizen($cidadao, '(88) 98888-0003', $assessorA);
        // A própria Secretaria e outra Câmara ficam de fora do público.
        $this->staff($secretaria, '(88) 99999-0004');
        $outraCamara = $this->camara();
        $this->staff($this->gabinete($outraCamara, OfficeType::CouncilorOffice), '(88) 99999-0005');
        $compromisso = $this->compromisso($secretaria, ['titulo' => 'Sessão ordinária', 'local' => 'Plenário']);

        $this->convocar($this->usuario($secretaria), $compromisso)
            ->assertInertiaFlash('toast', [
                'type' => 'success',
                'message' => 'Convocação enviada a 2 pessoas em 2 gabinetes.',
            ]);

        $notifications = WhatsAppNotification::withoutGlobalScopes()->orderBy('gabinete_id')->get();
        $this->assertSame([$vereadorA->id, $vereadorB->id], $notifications->pluck('gabinete_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(
            [$assessorA->id, $assessorB->id],
            $notifications->map(fn (WhatsAppNotification $notification): int => (int) WhatsAppContact::withoutGlobalScopes()
                ->findOrFail($notification->whatsapp_contato_id)->usuario_id)->all(),
        );
        $first = $notifications->first();
        $this->assertSame(self::PURPOSE, $first->finalidade);
        $this->assertSame($compromisso->getMorphClass(), $first->origem_type);
        $this->assertSame($compromisso->id, (int) $first->origem_id);
        $this->assertSame(
            [$assessorA->name, 'Sessão ordinária', '10/11/2026 às 09:00', 'Plenário'],
            $first->parametros_corpo_criptografados,
        );
    }

    public function test_restricted_audience_reaches_only_the_target_offices(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $membro = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $foraDaLista = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $this->staff($membro, '(88) 99999-0011');
        $this->staff($membro, '(88) 99999-0012');
        $this->staff($foraDaLista, '(88) 99999-0013');
        $compromisso = $this->compromisso($secretaria, ['dia_inteiro' => true, 'local' => null], [$membro]);

        $this->convocar($this->usuario($secretaria), $compromisso)
            ->assertInertiaFlash('toast', [
                'type' => 'success',
                'message' => 'Convocação enviada a 2 pessoas em 1 gabinete.',
            ]);

        $notifications = WhatsAppNotification::withoutGlobalScopes()->get();
        $this->assertCount(2, $notifications);
        $this->assertTrue($notifications->every(fn (WhatsAppNotification $item): bool => (int) $item->gabinete_id === $membro->id));
        $this->assertSame(['10/11/2026, dia inteiro', 'A confirmar'], array_slice($notifications->first()->parametros_corpo_criptografados, 2));
    }

    public function test_only_the_publishing_secretariat_can_convoke(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $this->staff($vereador, '(88) 99999-0021');
        $compromisso = $this->compromisso($secretaria);
        $outraSecretaria = $this->gabinete($this->camara(), OfficeType::LegislativeSecretariat);

        foreach ([
            $this->usuario($vereador),
            User::factory()->auditor()->forGabinete($secretaria)->create(),
            $this->usuario($outraSecretaria),
        ] as $user) {
            $this->actingAs($user)
                ->post(route('appointments.institutional.broadcast', $compromisso))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('whatsapp_notificacoes', 0);
        $this->assertFalse($this->usuario($vereador)->can('broadcastWhatsApp', $compromisso));
        $this->assertTrue($this->usuario($secretaria)->can('broadcastWhatsApp', $compromisso));
    }

    public function test_ineligible_contacts_are_skipped_without_errors(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $elegivel = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $semFinalidade = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $semModulo = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $this->staff($elegivel, '(88) 99999-0031');
        $revogado = $this->staff($elegivel, '(88) 99999-0032');
        $inativo = $this->staff($elegivel, '(88) 99999-0033');
        $pausado = $this->staff($elegivel, '(88) 99999-0036');
        $this->staff($semFinalidade, '(88) 99999-0034', purposes: [WhatsAppPurpose::AppointmentStaffReminder]);
        $this->staff($semModulo, '(88) 99999-0035');
        app(WhatsAppContactService::class)->revoke(
            WhatsAppContact::withoutGlobalScopes()->where('usuario_id', $revogado->id)->sole(),
            $revogado,
        );
        $inativo->forceFill(['is_active' => false])->save();
        // Pausado pelo Time (`gabinete_membros.ativo = false`): diferente do
        // `$inativo` acima, `users.is_active` continua `true` — só o vínculo
        // de equipe muda.
        OfficeMember::query()->updateOrCreate(
            ['gabinete_id' => $elegivel->id, 'usuario_id' => $pausado->id],
            ['papel' => AccessRole::Operator, 'ativo' => false, 'ingressou_em' => now(), 'desativado_em' => now()],
        );
        OfficeModuleSubscription::query()
            ->where('gabinete_id', $semModulo->id)
            ->where('modulo', OfficeModule::WhatsApp->value)
            ->update(['ativo' => false]);
        app()->forgetScopedInstances();
        $compromisso = $this->compromisso($secretaria);

        $this->convocar($this->usuario($secretaria), $compromisso)
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', [
                'type' => 'success',
                // O pausado é filtrado antes de chegar à elegibilidade
                // (mesmo balde do `$inativo`), então não entra nessa
                // contagem — a prova de que ele não foi convocado está na
                // asserção abaixo, que exige exatamente 1 notificação.
                'message' => 'Convocação enviada a 1 pessoa em 1 gabinete. 3 contatos ficaram de fora por consentimento ou configuração do gabinete.',
            ]);

        $this->assertSame([$elegivel->id], WhatsAppNotification::withoutGlobalScopes()->pluck('gabinete_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_repeating_the_convocation_does_not_duplicate_until_rescheduled(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $this->staff($vereador, '(88) 99999-0041');
        $compromisso = $this->compromisso($secretaria);
        $admin = $this->usuario($secretaria);

        $this->convocar($admin, $compromisso);
        $this->travel(5)->minutes();
        $this->convocar($admin, $compromisso)
            ->assertInertiaFlash('toast', [
                'type' => 'info',
                'message' => 'Nenhuma convocação nova: 1 pessoa já tinha recebido esta convocação.',
            ]);
        $this->assertDatabaseCount('whatsapp_notificacoes', 1);

        // Remarcado, a convocação sai de novo com o horário novo.
        $compromisso->update([
            'inicio_em' => CarbonImmutable::parse(self::DAY.' 14:00', self::TIMEZONE)->utc(),
            'fim_em' => CarbonImmutable::parse(self::DAY.' 16:00', self::TIMEZONE)->utc(),
        ]);
        $this->convocar($admin, $compromisso->fresh());
        $this->assertDatabaseCount('whatsapp_notificacoes', 2);
    }

    public function test_cancelled_or_finished_appointments_are_not_convoked(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $this->staff($vereador, '(88) 99999-0051');
        $cancelado = $this->compromisso($secretaria, ['status' => AppointmentStatus::Cancelled]);
        $encerrado = $this->compromisso($secretaria, [
            'inicio_em' => CarbonImmutable::parse('2026-10-20 09:00', self::TIMEZONE)->utc(),
            'fim_em' => CarbonImmutable::parse('2026-10-20 12:00', self::TIMEZONE)->utc(),
        ]);
        $admin = $this->usuario($secretaria);

        foreach ([$cancelado, $encerrado] as $compromisso) {
            $this->convocar($admin, $compromisso)
                ->assertInertiaFlash('toast', [
                    'type' => 'error',
                    'message' => 'Compromisso cancelado ou já encerrado não recebe convocação.',
                ]);
            $this->actingAs($admin)
                ->get(route('appointments.institutional.edit', $compromisso))
                ->assertInertia(fn (Assert $page) => $page->where('broadcast.available', false));
        }

        $this->assertDatabaseCount('whatsapp_notificacoes', 0);
    }

    public function test_edit_page_offers_the_convocation_only_with_whatsapp_on_in_the_city_council(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $membro = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $this->gabinete($camara, OfficeType::CouncilorOffice);
        $compromisso = $this->compromisso($secretaria, [], [$membro]);
        $admin = $this->usuario($secretaria);

        $this->actingAs($admin)
            ->get(route('appointments.institutional.edit', $compromisso))
            ->assertInertia(fn (Assert $page) => $page
                ->where('broadcast.available', true)
                ->where('broadcast.offices', 1));
        $this->actingAs($admin)
            ->get(route('appointments.institutional.create'))
            ->assertInertia(fn (Assert $page) => $page->where('broadcast', null));

        EntityModuleSubscription::query()
            ->where('entidade_id', $camara->id)
            ->where('modulo', EntityModule::WhatsApp->value)
            ->update(['ativo' => false]);
        app()->forgetScopedInstances();

        $this->actingAs($admin)
            ->get(route('appointments.institutional.edit', $compromisso))
            ->assertInertia(fn (Assert $page) => $page->where('broadcast.available', false));
    }

    private function convocar(User $user, CompromissoInstitucional $compromisso): TestResponse
    {
        $edit = route('appointments.institutional.edit', $compromisso);

        return $this->actingAs($user)
            ->from($edit)
            ->post(route('appointments.institutional.broadcast', $compromisso))
            ->assertRedirect($edit);
    }

    /**
     * Pessoa da equipe com WhatsApp declarado e o gabinete pronto para a
     * finalidade (canal, configuração e template da Câmara).
     *
     * @param  list<WhatsAppPurpose>  $purposes
     */
    private function staff(Office $office, string $phone, array $purposes = [self::PURPOSE]): User
    {
        $user = User::factory()->forGabinete($office)->create();
        app(WhatsAppContactService::class)->declareForUser($user, $phone, $user);
        $this->entidadeWhatsAppConnection($office);
        app(WhatsAppConfigurationService::class)->update(
            $office,
            WhatsAppMode::Live,
            '08:00',
            array_map(fn (WhatsAppPurpose $purpose): string => $purpose->value, $purposes),
        );
        $this->readyEntidadeWhatsAppTemplate($office, self::PURPOSE);

        return $user;
    }

    private function camara(): Entity
    {
        $camara = Entity::factory()->create([
            'tipo' => EntityType::CityCouncil,
            'interface_simplificada' => false,
        ]);
        EntityModuleSubscription::query()
            ->where('entidade_id', $camara->id)
            ->where('modulo', EntityModule::LegislativeSecretariat->value)
            ->update(['ativo' => true, 'ativado_em' => now()]);

        return $camara;
    }

    private function gabinete(Entity $entidade, OfficeType $tipo): Office
    {
        return Office::factory()->create([
            'entidade_id' => $entidade->id,
            'tipo_gabinete' => $tipo,
            'timezone' => self::TIMEZONE,
        ]);
    }

    private function usuario(Office $gabinete): User
    {
        return User::factory()->administrator()->forGabinete($gabinete)->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<Office>  $alvos
     */
    private function compromisso(Office $secretaria, array $attributes = [], array $alvos = []): CompromissoInstitucional
    {
        return CompromissoInstitucional::factory()
            ->publishedBy($secretaria)
            ->targeting($alvos)
            ->create([
                'inicio_em' => CarbonImmutable::parse(self::DAY.' 09:00', self::TIMEZONE)->utc(),
                'fim_em' => CarbonImmutable::parse(self::DAY.' 12:00', self::TIMEZONE)->utc(),
                ...$attributes,
            ]);
    }
}

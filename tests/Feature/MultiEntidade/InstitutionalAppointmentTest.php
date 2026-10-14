<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\AppointmentStatus;
use App\Enums\CommitteeRole;
use App\Enums\CommitteeStatus;
use App\Enums\CommitteeType;
use App\Enums\EntidadeModule;
use App\Enums\EntidadeType;
use App\Enums\EventType;
use App\Enums\GabineteModule;
use App\Enums\GabineteType;
use App\Models\Appointment;
use App\Models\ComissaoLegislativa;
use App\Models\CompromissoInstitucional;
use App\Models\Entidade;
use App\Models\EntidadeModulo;
use App\Models\Evento;
use App\Models\Gabinete;
use App\Models\GabineteModulo;
use App\Models\User;
use App\Services\Committees\CommitteeMembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstitutionalAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/Sao_Paulo';

    private const DAY = '2026-11-10';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00', self::TIMEZONE));
    }

    public function test_appointment_without_targets_reaches_every_councilor_office_of_the_city_council(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $vereadorA = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $vereadorB = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $setor = $this->gabinete($camara, GabineteType::AdministrativeDepartment);
        $compromisso = $this->compromisso($secretaria, ['titulo' => 'Sessão ordinária']);

        foreach ([$vereadorA, $vereadorB] as $vereador) {
            $this->agenda($this->usuario($vereador))
                ->assertInertia(fn (Assert $page) => $page
                    ->has('appointments', 1)
                    ->where('appointments.0.id', $compromisso->id)
                    ->where('appointments.0.source', 'institutional')
                    ->where('appointments.0.title', 'Sessão ordinária')
                    ->where('appointments.0.origin_office.name', $secretaria->nome)
                    ->where('appointments.0.origin_office.type_label', 'Secretaria legislativa')
                    ->where('appointments.0.can_edit', false)
                    ->where('canManageInstitutional', false));
        }

        // Sem alvo, o público são os gabinetes de vereador — o setor
        // administrativo da Câmara só vê quando listado.
        $this->agenda($this->usuario($setor))
            ->assertInertia(fn (Assert $page) => $page->has('appointments', 0));

        // Quem publicou sempre vê, e só ela edita.
        $this->agenda($this->usuario($secretaria))
            ->assertInertia(fn (Assert $page) => $page
                ->has('appointments', 1)
                ->where('appointments.0.can_edit', true)
                ->where('canManageInstitutional', true));
    }

    public function test_appointment_with_targets_reaches_only_the_listed_offices(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $membro = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $foraDaLista = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $compromisso = $this->compromisso($secretaria, ['titulo' => 'Reunião da comissão de finanças'], [$membro]);

        $this->agenda($this->usuario($membro))
            ->assertInertia(fn (Assert $page) => $page
                ->has('appointments', 1)
                ->where('appointments.0.id', $compromisso->id));

        $this->agenda($this->usuario($foraDaLista))
            ->assertInertia(fn (Assert $page) => $page->has('appointments', 0));

        $this->agenda($this->usuario($secretaria))
            ->assertInertia(fn (Assert $page) => $page->has('appointments', 1));

        $this->assertTrue($this->usuario($membro)->can('view', $compromisso));
        $this->assertFalse($this->usuario($foraDaLista)->can('view', $compromisso));
    }

    public function test_offices_of_another_city_council_never_see_or_change_it(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $compromisso = $this->compromisso($secretaria);
        $outraCamara = $this->camara();
        $outraSecretaria = $this->gabinete($outraCamara, GabineteType::LegislativeSecretariat);
        $vereadorDeFora = $this->gabinete($outraCamara, GabineteType::CouncilorOffice);
        $independente = Gabinete::factory()->create(['timezone' => self::TIMEZONE]);

        foreach ([$vereadorDeFora, $outraSecretaria, $independente] as $gabinete) {
            $user = $this->usuario($gabinete);

            $this->agenda($user)
                ->assertInertia(fn (Assert $page) => $page->has('appointments', 0));
            $this->assertFalse($user->can('view', $compromisso));
        }

        // A Secretaria de outra Câmara tem o módulo, mas não o registro.
        $outro = $this->usuario($outraSecretaria);
        $this->actingAs($outro)
            ->get(route('appointments.institutional.edit', $compromisso))
            ->assertForbidden();
        $this->actingAs($outro)
            ->put(route('appointments.institutional.update', $compromisso), $this->payload())
            ->assertForbidden();
        $this->actingAs($outro)
            ->delete(route('appointments.institutional.destroy', $compromisso))
            ->assertForbidden();

        $this->assertNotSoftDeleted($compromisso);
        $this->assertSame($compromisso->titulo, $compromisso->fresh()->titulo);
    }

    public function test_only_the_secretariat_creates_updates_and_deletes(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $outroVereador = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $admin = $this->usuario($secretaria);

        // Vereador não tem o módulo de gestão; auditor da Secretaria só lê.
        $this->actingAs($this->usuario($vereador))
            ->get(route('appointments.institutional.create'))
            ->assertForbidden();
        $this->actingAs($this->usuario($vereador))
            ->post(route('appointments.institutional.store'), $this->payload())
            ->assertForbidden();
        $this->actingAs(User::factory()->auditor()->forGabinete($secretaria)->create())
            ->post(route('appointments.institutional.store'), $this->payload())
            ->assertForbidden();
        $this->assertDatabaseCount('compromissos_institucionais', 0);

        $this->actingAs($admin)
            ->get(route('appointments.institutional.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('appointments/institutional-form')
                ->has('options.offices', 2)
                ->has('options.statuses'));

        $this->actingAs($admin)
            ->post(route('appointments.institutional.store'), $this->payload([
                'titulo' => 'Sessão solene',
                'gabinetes' => [$vereador->id],
            ]))
            ->assertRedirect(route('appointments.index', ['view' => 'dia', 'date' => self::DAY]));

        $compromisso = CompromissoInstitucional::query()->sole();
        $this->assertSame($camara->id, $compromisso->entidade_id);
        $this->assertSame($secretaria->id, $compromisso->criado_por_gabinete_id);
        $this->assertSame($admin->id, $compromisso->criado_por_id);
        $this->assertSame('2026-11-10 12:00:00', $compromisso->inicio_em->utc()->format('Y-m-d H:i:s'));
        $this->assertSame([$vereador->id], $compromisso->gabinetesAlvo()->pluck('gabinetes.id')->all());
        $this->assertNull($compromisso->comissao_id);

        $this->actingAs($admin)
            ->get(route('appointments.institutional.edit', $compromisso))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointment.comissao_id', null)
                ->where('appointment.inicio_em', '2026-11-10T09:00')
                ->where('appointment.gabinetes', [$vereador->id]));

        // Esvaziar os alvos devolve o compromisso a todos os vereadores.
        $this->actingAs($admin)
            ->put(route('appointments.institutional.update', $compromisso), $this->payload([
                'titulo' => 'Sessão solene remarcada',
                'status' => AppointmentStatus::Confirmed->value,
                'gabinetes' => [],
            ]))
            ->assertRedirect();
        $this->assertSame('Sessão solene remarcada', $compromisso->fresh()->titulo);
        $this->assertSame(AppointmentStatus::Confirmed, $compromisso->fresh()->status);
        $this->assertDatabaseCount('compromisso_institucional_gabinetes', 0);
        $this->agenda($this->usuario($outroVereador))
            ->assertInertia(fn (Assert $page) => $page->has('appointments', 1));

        $this->actingAs($this->usuario($vereador))
            ->delete(route('appointments.institutional.destroy', $compromisso))
            ->assertForbidden();
        $this->actingAs($admin)
            ->delete(route('appointments.institutional.destroy', $compromisso))
            ->assertRedirect(route('appointments.index'));
        $this->assertSoftDeleted($compromisso);
    }

    public function test_targets_must_be_active_councilor_offices_of_the_same_city_council(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $setor = $this->gabinete($camara, GabineteType::AdministrativeDepartment);
        $suspenso = Gabinete::factory()->suspended()->create([
            'entidade_id' => $camara->id,
            'tipo_gabinete' => GabineteType::CouncilorOffice,
        ]);
        $deFora = $this->gabinete($this->camara(), GabineteType::CouncilorOffice);

        foreach ([$setor, $suspenso, $deFora, $secretaria] as $alvo) {
            $this->actingAs($this->usuario($secretaria))
                ->post(route('appointments.institutional.store'), $this->payload(['gabinetes' => [$alvo->id]]))
                ->assertSessionHasErrors('gabinetes.0');
        }

        $this->actingAs($this->usuario($secretaria))
            ->post(route('appointments.institutional.store'), $this->payload([
                'inicio_em' => '2026-10-30T09:00',
                'fim_em' => '2026-10-30T10:00',
            ]))
            ->assertSessionHasErrors('inicio_em');

        $this->assertDatabaseCount('compromissos_institucionais', 0);
    }

    public function test_committee_meeting_targets_only_the_active_members_of_the_committee(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $membroA = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $membroB = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $exMembro = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $foraDaComissao = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $comissao = $this->comissao($camara, [$membroA, $membroB, $exMembro]);
        $comissao->membros()->where('gabinete_id', $exMembro->id)->update(['saiu_em' => '2026-10-01']);
        $admin = $this->usuario($secretaria);

        $this->actingAs($admin)
            ->get(route('appointments.institutional.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('options.committees', 1)
                ->where('options.committees.0.id', $comissao->id)
                ->where('options.committees.0.offices', [$membroA->id, $membroB->id]));

        // A lista manual enviada junto é ignorada: o público sai da comissão.
        $this->actingAs($admin)
            ->post(route('appointments.institutional.store'), $this->payload([
                'titulo' => 'Reunião da comissão de educação',
                'comissao_id' => $comissao->id,
                'gabinetes' => [$foraDaComissao->id],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $compromisso = CompromissoInstitucional::query()->sole();
        $this->assertSame($comissao->id, $compromisso->comissao_id);
        $this->assertSame($comissao->id, $compromisso->comissao?->id);
        $this->assertSame([$membroA->id, $membroB->id], $this->alvos($compromisso));

        foreach ([$membroA, $membroB] as $membro) {
            $this->agenda($this->usuario($membro))
                ->assertInertia(fn (Assert $page) => $page->has('appointments', 1));
        }
        foreach ([$exMembro, $foraDaComissao] as $gabinete) {
            $this->agenda($this->usuario($gabinete))
                ->assertInertia(fn (Assert $page) => $page->has('appointments', 0));
        }

        $this->actingAs($admin)
            ->get(route('appointments.institutional.edit', $compromisso))
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointment.comissao_id', $comissao->id)
                ->where('appointment.gabinetes', [$membroA->id, $membroB->id]));
    }

    public function test_committee_audience_is_a_snapshot_taken_when_linking(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $membroA = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $membroB = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $novato = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $comissao = $this->comissao($camara, [$membroA, $membroB]);
        $outraComissao = $this->comissao($camara, [$novato], 'Comissão de Finanças');
        $admin = $this->usuario($secretaria);

        $this->actingAs($admin)
            ->post(route('appointments.institutional.store'), $this->payload(['comissao_id' => $comissao->id]))
            ->assertSessionHasNoErrors();
        $compromisso = CompromissoInstitucional::query()->sole();

        // A composição muda depois: o compromisso já gravado não acompanha.
        $memberships = app(CommitteeMembershipService::class);
        $memberships->add($comissao, $novato->id, null, CommitteeRole::Substitute);
        $memberships->remove($comissao->membros()->where('gabinete_id', $membroA->id)->sole());
        $this->assertSame([$membroA->id, $membroB->id], $this->alvos($compromisso));

        // Editar mantendo a mesma comissão preserva o retrato.
        $this->actingAs($admin)
            ->put(route('appointments.institutional.update', $compromisso), $this->payload([
                'titulo' => 'Reunião remarcada',
                'comissao_id' => $comissao->id,
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Reunião remarcada', $compromisso->fresh()->titulo);
        $this->assertSame([$membroA->id, $membroB->id], $this->alvos($compromisso));

        // Trocar de comissão tira um retrato novo.
        $this->actingAs($admin)
            ->put(route('appointments.institutional.update', $compromisso), $this->payload([
                'comissao_id' => $outraComissao->id,
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame($outraComissao->id, $compromisso->fresh()->comissao_id);
        $this->assertSame([$novato->id], $this->alvos($compromisso));

        // Tirar a comissão volta para a lista manual (vazia = todos).
        $this->actingAs($admin)
            ->put(route('appointments.institutional.update', $compromisso), $this->payload([
                'comissao_id' => null,
                'gabinetes' => [],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertNull($compromisso->fresh()->comissao_id);
        $this->assertSame([], $this->alvos($compromisso));

        // Excluir a comissão de vez não leva o compromisso junto.
        $this->actingAs($admin)
            ->put(route('appointments.institutional.update', $compromisso), $this->payload([
                'comissao_id' => $outraComissao->id,
            ]))
            ->assertSessionHasNoErrors();
        $outraComissao->forceDelete();
        $this->assertNotSoftDeleted($compromisso);
        $this->assertNull($compromisso->fresh()->comissao_id);
        $this->assertSame([$novato->id], $this->alvos($compromisso));
    }

    public function test_committee_must_be_an_active_committee_of_the_same_city_council_with_members(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $outraCamara = $this->camara();
        $deFora = $this->comissao($outraCamara, [$this->gabinete($outraCamara, GabineteType::CouncilorOffice)]);
        $vazia = $this->comissao($camara, [], 'Comissão sem membros');
        $extinta = $this->comissao($camara, [$vereador], 'Comissão extinta');
        $extinta->forceFill(['status' => CommitteeStatus::Extinct])->save();
        $admin = $this->usuario($secretaria);

        $this->actingAs($admin)
            ->get(route('appointments.institutional.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('options.committees', 1)
                ->where('options.committees.0.id', $vazia->id));

        foreach ([$deFora, $vazia, $extinta] as $comissao) {
            $this->actingAs($admin)
                ->post(route('appointments.institutional.store'), $this->payload(['comissao_id' => $comissao->id]))
                ->assertSessionHasErrors('comissao_id');
        }
        $this->actingAs($admin)
            ->post(route('appointments.institutional.store'), $this->payload(['comissao_id' => 999999]))
            ->assertSessionHasErrors('comissao_id');

        // A recusa desfaz a gravação inteira.
        $this->assertDatabaseCount('compromissos_institucionais', 0);
        $this->assertDatabaseCount('compromisso_institucional_gabinetes', 0);
    }

    public function test_agenda_merges_institutional_items_with_appointments_and_events(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $user = $this->usuario($vereador);
        $at = fn (string $time) => CarbonImmutable::parse(self::DAY.' '.$time, self::TIMEZONE)->utc();
        $appointment = Appointment::factory()->forGabinete($vereador)->create([
            'inicio_em' => $at('08:00'),
            'fim_em' => $at('09:00'),
        ]);
        $compromisso = $this->compromisso($secretaria, [
            'titulo' => 'Sessão ordinária',
            'tipo' => 'Sessão',
            'local' => 'Plenário',
            'inicio_em' => $at('09:00'),
            'fim_em' => $at('12:00'),
        ]);
        $event = Evento::factory()->forGabinete($vereador, $user, $user)->create([
            'tipo' => EventType::Assembly,
            'inicio_em' => $at('14:00'),
            'fim_em' => $at('16:00'),
        ]);

        $this->agenda($user)
            ->assertInertia(fn (Assert $page) => $page
                ->has('appointments', 3)
                ->where('appointments.0.source', 'appointment')
                ->where('appointments.0.id', $appointment->id)
                ->where('appointments.1.source', 'institutional')
                ->where('appointments.1.id', $compromisso->id)
                ->where('appointments.1.occurrence_key', 'institutional-'.$compromisso->id.'-20261110120000')
                ->where('appointments.1.starts_at', '2026-11-10T12:00:00+00:00')
                ->where('appointments.1.ends_at', '2026-11-10T15:00:00+00:00')
                ->where('appointments.1.type', 'Sessão')
                ->where('appointments.1.location', 'Plenário')
                ->where('appointments.1.status', AppointmentStatus::Scheduled->value)
                ->where('appointments.1.status_label', 'Agendado')
                ->where('appointments.1.recurrence', 'nenhuma')
                ->where('appointments.1.responsible', null)
                ->where('appointments.1.participants', [])
                ->where('appointments.1.reminders', [])
                ->where('appointments.1.is_past', false)
                ->has('appointments.1.created_by')
                ->where('appointments.1.origin_office.id', $secretaria->id)
                ->where('appointments.2.source', 'event')
                ->where('appointments.2.id', $event->id));

        // Filtrar por integrante tira o institucional (não tem responsável aqui).
        $this->actingAs($user)
            ->get(route('appointments.index', ['view' => 'dia', 'date' => self::DAY, 'responsavel_id' => $user->id]))
            ->assertInertia(fn (Assert $page) => $page->where('appointments', fn ($items) => collect($items)
                ->every(fn (array $item): bool => $item['source'] !== 'institutional')));
    }

    public function test_secretariat_does_not_gain_access_to_councilor_private_agenda(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $privado = Appointment::factory()->forGabinete($vereador)->create([
            'inicio_em' => CarbonImmutable::parse(self::DAY.' 10:00', self::TIMEZONE)->utc(),
            'fim_em' => CarbonImmutable::parse(self::DAY.' 11:00', self::TIMEZONE)->utc(),
        ]);

        $this->agenda($this->usuario($secretaria))
            ->assertInertia(fn (Assert $page) => $page->has('appointments', 0));
        $this->actingAs($this->usuario($secretaria))
            ->put(route('appointments.update', $privado), [])
            ->assertNotFound();
    }

    public function test_disabled_modules_hide_the_institutional_agenda(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $this->compromisso($secretaria);

        EntidadeModulo::query()
            ->where('entidade_id', $camara->id)
            ->where('modulo', EntidadeModule::LegislativeSecretariat->value)
            ->update(['ativo' => false]);

        foreach ([$vereador, $secretaria] as $gabinete) {
            $this->agenda($this->usuario($gabinete))
                ->assertInertia(fn (Assert $page) => $page
                    ->has('appointments', 0)
                    ->where('canManageInstitutional', false));
        }
        $this->actingAs($this->usuario($secretaria))
            ->post(route('appointments.institutional.store'), $this->payload())
            ->assertForbidden();

        // Com a Câmara ligada de novo, o vereador sem Agenda continua sem ver.
        EntidadeModulo::query()
            ->where('entidade_id', $camara->id)
            ->where('modulo', EntidadeModule::LegislativeSecretariat->value)
            ->update(['ativo' => true]);
        GabineteModulo::query()
            ->where('gabinete_id', $vereador->id)
            ->where('modulo', GabineteModule::Schedule->value)
            ->update(['ativo' => false]);
        // O cache de módulos vale por requisição; a próxima começa limpa.
        app()->forgetScopedInstances();

        $this->actingAs($this->usuario($vereador))
            ->get(route('appointments.index', ['view' => 'dia', 'date' => self::DAY]))
            ->assertForbidden();
    }

    private function agenda(User $user): TestResponse
    {
        return $this->actingAs($user)
            ->get(route('appointments.index', ['view' => 'dia', 'date' => self::DAY]))
            ->assertOk();
    }

    private function camara(): Entidade
    {
        $camara = Entidade::factory()->create([
            'tipo' => EntidadeType::CityCouncil,
            'interface_simplificada' => false,
        ]);
        EntidadeModulo::query()
            ->where('entidade_id', $camara->id)
            ->where('modulo', EntidadeModule::LegislativeSecretariat->value)
            ->update(['ativo' => true, 'ativado_em' => now()]);

        return $camara;
    }

    private function gabinete(Entidade $entidade, GabineteType $tipo): Gabinete
    {
        return Gabinete::factory()->create([
            'entidade_id' => $entidade->id,
            'tipo_gabinete' => $tipo,
            'timezone' => self::TIMEZONE,
        ]);
    }

    private function usuario(Gabinete $gabinete): User
    {
        return User::factory()->administrator()->forGabinete($gabinete)->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<Gabinete>  $alvos
     */
    private function compromisso(Gabinete $secretaria, array $attributes = [], array $alvos = []): CompromissoInstitucional
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

    /** @param  list<Gabinete>  $membros */
    private function comissao(Entidade $entidade, array $membros, string $nome = 'Comissão de Educação'): ComissaoLegislativa
    {
        $comissao = new ComissaoLegislativa;
        $comissao->forceFill([
            'entidade_id' => $entidade->id,
            'nome' => $nome,
            'tipo' => CommitteeType::Permanent,
            'status' => CommitteeStatus::Active,
            'criado_por_id' => User::factory()->create()->id,
        ])->save();

        foreach ($membros as $membro) {
            app(CommitteeMembershipService::class)->add($comissao, $membro->id, null, CommitteeRole::Member);
        }

        return $comissao;
    }

    /** @return list<int> */
    private function alvos(CompromissoInstitucional $compromisso): array
    {
        return array_values($compromisso->gabinetesAlvo()
            ->orderBy('gabinetes.id')
            ->pluck('gabinetes.id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'titulo' => 'Sessão ordinária',
            'descricao' => 'Pauta da semana.',
            'inicio_em' => self::DAY.'T09:00',
            'fim_em' => self::DAY.'T12:00',
            'dia_inteiro' => false,
            'local' => 'Plenário',
            'tipo' => 'Sessão',
            'status' => AppointmentStatus::Scheduled->value,
            'gabinetes' => [],
            ...$overrides,
        ];
    }
}

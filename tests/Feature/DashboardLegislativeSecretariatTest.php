<?php

namespace Tests\Feature;

use App\Enums\CommitteeRole;
use App\Enums\CommitteeStatus;
use App\Enums\CommitteeType;
use App\Enums\EntidadeModule;
use App\Enums\EntidadeType;
use App\Enums\GabineteType;
use App\Models\Appointment;
use App\Models\ComissaoLegislativa;
use App\Models\CompromissoInstitucional;
use App\Models\Entidade;
use App\Models\EntidadeModulo;
use App\Models\Gabinete;
use App\Models\User;
use App\Services\Committees\CommitteeMembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A Secretaria legislativa nunca tem o módulo Demandas, então a Visão geral
 * dela usa `DashboardMetricsService::buildLegislativeSecretariat()` em vez do
 * branch genérico (quase todo zerado). Cobre compromissos de hoje (próprios +
 * institucionais visíveis) e o recorte de comissões/membros ativos.
 */
class DashboardLegislativeSecretariatTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/Sao_Paulo';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', self::TIMEZONE));
    }

    public function test_dashboard_counts_todays_own_and_institutional_appointments(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $admin = $this->usuario($secretaria);

        // Compromisso privado da própria Secretaria, hoje: conta.
        Appointment::factory()->forGabinete($secretaria)->create([
            'criado_por_id' => $admin->id,
            'titulo' => 'Reunião interna',
            'inicio_em' => CarbonImmutable::parse('2026-11-10 09:00', self::TIMEZONE)->utc(),
            'fim_em' => CarbonImmutable::parse('2026-11-10 10:00', self::TIMEZONE)->utc(),
        ]);

        // Compromisso privado de outro gabinete da mesma Câmara, hoje: não
        // pertence à Secretaria e não deve ser somado (isolamento de tenant).
        Appointment::factory()->forGabinete($vereador)->create([
            'titulo' => 'Atendimento do vereador',
            'inicio_em' => CarbonImmutable::parse('2026-11-10 09:00', self::TIMEZONE)->utc(),
            'fim_em' => CarbonImmutable::parse('2026-11-10 10:00', self::TIMEZONE)->utc(),
        ]);

        // Compromisso privado da própria Secretaria, mas de outro dia: não conta.
        Appointment::factory()->forGabinete($secretaria)->create([
            'criado_por_id' => $admin->id,
            'titulo' => 'Reunião de amanhã',
            'inicio_em' => CarbonImmutable::parse('2026-11-11 09:00', self::TIMEZONE)->utc(),
            'fim_em' => CarbonImmutable::parse('2026-11-11 10:00', self::TIMEZONE)->utc(),
        ]);

        // Institucional publicado pela própria Secretaria, hoje, visível a
        // ela (quem publica sempre enxerga o que publicou): conta.
        CompromissoInstitucional::factory()
            ->publishedBy($secretaria)
            ->targeting([$vereador])
            ->create([
                'titulo' => 'Sessão ordinária',
                'inicio_em' => CarbonImmutable::parse('2026-11-10 14:00', self::TIMEZONE)->utc(),
                'fim_em' => CarbonImmutable::parse('2026-11-10 16:00', self::TIMEZONE)->utc(),
            ]);

        // Institucional de outra Câmara (outra entidade), também hoje: nunca
        // pode ser somado ao total desta Secretaria.
        $outraCamara = $this->camara();
        $outraSecretaria = $this->gabinete($outraCamara, GabineteType::LegislativeSecretariat);
        CompromissoInstitucional::factory()
            ->publishedBy($outraSecretaria)
            ->create([
                'titulo' => 'Sessão de outra Câmara',
                'inicio_em' => CarbonImmutable::parse('2026-11-10 09:00', self::TIMEZONE)->utc(),
                'fim_em' => CarbonImmutable::parse('2026-11-10 10:00', self::TIMEZONE)->utc(),
            ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('capabilities.demands', false)
                ->where('capabilities.legislativeSecretariat', true)
                ->where('legislativeSecretariat.appointments_today', 2));
    }

    public function test_dashboard_committee_metrics_count_only_the_offices_entidade(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $membroA = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $membroB = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $exMembro = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $admin = $this->usuario($secretaria);

        $comissaoAtiva = $this->comissao($camara, [$membroA, $membroB, $exMembro], 'Comissão de Finanças');
        $comissaoAtiva->membros()->where('gabinete_id', $exMembro->id)->update(['saiu_em' => '2026-11-01']);

        // Comissão extinta: não entra em "ativas" nem nos membros contados.
        $comissaoExtinta = $this->comissao($camara, [$membroA], 'Comissão extinta');
        $comissaoExtinta->forceFill(['status' => CommitteeStatus::Extinct])->save();

        // Comissão de outra Câmara: isolamento entre entidades.
        $outraCamara = $this->camara();
        $outroMembro = $this->gabinete($outraCamara, GabineteType::CouncilorOffice);
        $this->comissao($outraCamara, [$outroMembro], 'Comissão de outra Câmara');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('legislativeSecretariat.active_committees', 1)
                ->where('legislativeSecretariat.committee_members', 2));
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

    /** @param  list<Gabinete>  $membros */
    private function comissao(Entidade $entidade, array $membros, string $nome): ComissaoLegislativa
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
}

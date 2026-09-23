<?php

namespace Tests\Feature;

use App\Enums\DemandStatus;
use App\Models\Demanda;
use App\Models\DemandaAnexo;
use App\Models\DemandaEvento;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DemandReferralTest extends TestCase
{
    use RefreshDatabase;

    public function test_referral_is_registered_as_a_timeline_event_with_structured_fields(): void
    {
        [$user, $demand] = $this->demandContext();

        $this->actingAs($user)
            ->post(route('demands.referrals.store', $demand), $this->payload())
            ->assertRedirect(route('demands.show', $demand));

        $this->assertDatabaseHas('demanda_eventos', [
            'gabinete_id' => $user->gabinete_id,
            'demanda_id' => $demand->id,
            'usuario_id' => $user->id,
            'tipo' => 'encaminhamento',
            'destino' => 'Secretaria Municipal de Infraestrutura',
            'setor' => 'Coordenação de Manutenção',
        ]);
    }

    public function test_referral_has_no_situacao_field(): void
    {
        [$user, $demand] = $this->demandContext();

        $this->actingAs($user)
            ->post(route('demands.referrals.store', $demand), [
                ...$this->payload(),
                'situacao' => 'enviado',
            ])
            ->assertRedirect();

        $event = DemandaEvento::query()->where('tipo', 'encaminhamento')->sole();
        $this->assertArrayNotHasKey('situacao', $event->getAttributes());
    }

    public function test_registering_a_referral_on_a_new_demand_moves_it_to_in_progress(): void
    {
        [$user, $demand] = $this->demandContext();
        $this->assertSame(DemandStatus::New, $demand->status);

        $this->actingAs($user)
            ->post(route('demands.referrals.store', $demand), $this->payload());

        $this->assertSame(DemandStatus::InProgress, $demand->fresh()->status);
        $this->assertDatabaseHas('demanda_eventos', [
            'demanda_id' => $demand->id,
            'tipo' => 'status_alterado',
        ]);
    }

    public function test_referral_routes_prevent_cross_office_access(): void
    {
        [$user] = $this->demandContext();
        $foreignOffice = Gabinete::factory()->create();
        $foreignDemand = Demanda::factory()->forGabinete($foreignOffice)->create();

        $this->actingAs($user)->post(route('demands.referrals.store', $foreignDemand), $this->payload())
            ->assertNotFound();
    }

    public function test_response_can_be_registered_without_referencing_a_specific_referral(): void
    {
        [$user, $demand] = $this->demandContext();

        $this->actingAs($user)
            ->post(route('demands.referrals.respond', $demand), [
                'descricao' => 'O órgão informou que a manutenção foi concluída.',
            ])
            ->assertRedirect(route('demands.show', $demand));

        $this->assertDatabaseHas('demanda_eventos', [
            'demanda_id' => $demand->id,
            'tipo' => 'retorno_recebido',
            'descricao' => 'O órgão informou que a manutenção foi concluída.',
        ]);
        $this->assertSame(DemandStatus::New, $demand->fresh()->status);
    }

    public function test_response_can_close_a_pending_referral(): void
    {
        [$user, $demand] = $this->demandContext();
        $this->actingAs($user)->post(route('demands.referrals.store', $demand), $this->payload());
        $referral = DemandaEvento::query()->where('tipo', 'encaminhamento')->sole();

        $this->actingAs($user)
            ->post(route('demands.referrals.respond', $demand), [
                'descricao' => 'Retorno recebido do órgão.',
                'encaminhamento_id' => $referral->id,
            ])
            ->assertRedirect();

        $this->assertNotNull($referral->fresh()->retorno_recebido_em);
    }

    public function test_response_cannot_reference_a_referral_from_another_demand(): void
    {
        [$user, $demand] = $this->demandContext();
        $otherDemand = Demanda::factory()->forGabinete($user->gabinete, creator: $user)->create();
        $this->actingAs($user)->post(route('demands.referrals.store', $otherDemand), $this->payload());
        $foreignReferral = DemandaEvento::query()->where('tipo', 'encaminhamento')->sole();

        $this->actingAs($user)
            ->post(route('demands.referrals.respond', $demand), [
                'descricao' => 'Tentativa indevida.',
                'encaminhamento_id' => $foreignReferral->id,
            ])
            ->assertSessionHasErrors('encaminhamento_id');
    }

    public function test_demand_page_exposes_pending_referrals_and_suggestions_with_safe_attachment_metadata(): void
    {
        [$user, $demand] = $this->demandContext();
        $this->attachment($user, $demand);

        $this->actingAs($user)
            ->post(route('demands.referrals.store', $demand), [
                ...$this->payload(),
                'arquivos' => [],
            ]);

        $this->get(route('demands.show', $demand))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pendingReferrals', 1)
                ->has('demand.eventos')
                ->missing('demand.anexos.0.caminho')
                ->missing('demand.anexos.0.disk'));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'destino' => 'Secretaria Municipal de Infraestrutura',
            'setor' => 'Coordenação de Manutenção',
            'referencia_externa' => null,
            'descricao' => 'Solicitação de vistoria e providências para o endereço informado.',
            'prazo_esperado' => null,
        ];
    }

    /** @return array{User, Demanda} */
    private function demandContext(): array
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office, creator: $user)->create();

        return [$user, $demand];
    }

    private function attachment(User $user, Demanda $demand): DemandaAnexo
    {
        $attachment = new DemandaAnexo;
        $attachment->forceFill([
            'gabinete_id' => $user->gabinete_id,
            'demanda_id' => $demand->id,
            'usuario_id' => $user->id,
            'disk' => 'local',
            'caminho' => "gabinetes/{$user->gabinete_id}/demandas/{$demand->id}/documento.pdf",
            'nome_original' => 'oficio.pdf',
            'nome_armazenado' => 'documento.pdf',
            'mime_type' => 'application/pdf',
            'extensao' => 'pdf',
            'tamanho' => 100,
            'imagem' => false,
        ])->save();

        return $attachment;
    }
}

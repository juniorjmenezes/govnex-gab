<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\CommitteeRole;
use App\Enums\CommitteeStatus;
use App\Enums\CommitteeType;
use App\Enums\EntityModule;
use App\Enums\EntityType;
use App\Enums\OfficeType;
use App\Models\ComissaoLegislativa;
use App\Models\ComissaoMembro;
use App\Models\Entity;
use App\Models\EntityModuleSubscription;
use App\Models\KnowledgeDocument;
use App\Models\Office;
use App\Models\User;
use App\Services\Committees\CommitteeMembershipService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LegislativeCommitteeTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_secretariat_creates_committees(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $admin = $this->usuario($secretaria);

        // Vereador não tem o módulo de gestão; auditor da Secretaria só lê.
        $this->actingAs($this->usuario($vereador))
            ->post(route('committees.store'), $this->payload())
            ->assertForbidden();
        $this->actingAs(User::factory()->auditor()->forGabinete($secretaria)->create())
            ->post(route('committees.store'), $this->payload())
            ->assertForbidden();
        $this->assertDatabaseCount('comissoes_legislativas', 0);

        $this->actingAs($admin)
            ->post(route('committees.store'), $this->payload([
                'nome' => 'Comissão de Finanças e Orçamento',
                'sigla' => ' CFO ',
                // A entidade nunca vem do corpo.
                'entidade_id' => $this->camara()->id,
            ]))
            ->assertRedirect();

        $comissao = ComissaoLegislativa::query()->sole();
        $this->assertSame($camara->id, $comissao->entidade_id);
        $this->assertSame($admin->id, $comissao->criado_por_id);
        $this->assertSame('CFO', $comissao->sigla);
        $this->assertSame(CommitteeType::Permanent, $comissao->tipo);
        $this->assertSame(CommitteeStatus::Active, $comissao->status);

        // Nome repetido na mesma Câmara é recusado.
        $this->actingAs($admin)
            ->post(route('committees.store'), $this->payload(['nome' => 'Comissão de Finanças e Orçamento']))
            ->assertSessionHasErrors('nome');

        $this->actingAs($admin)
            ->get(route('committees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('committees/index')
                ->where('canManage', true)
                ->has('committees', 1)
                ->has('options.types', 2));
    }

    public function test_every_office_of_the_same_city_council_reads_committees(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $setor = $this->gabinete($camara, OfficeType::AdministrativeDepartment);
        $comissao = $this->comissao($camara, ['nome' => 'Comissão de Justiça']);
        $membro = app(CommitteeMembershipService::class)
            ->add($comissao, $vereador->id, null, CommitteeRole::President);
        $documento = $this->documento($camara);
        $comissao->documentos()->attach($documento->id, ['tipo_vinculo' => 'pauta']);

        foreach ([$vereador, $setor] as $gabinete) {
            $user = $this->usuario($gabinete);

            $this->actingAs($user)
                ->get(route('committees.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('canManage', false)
                    ->has('committees', 1)
                    ->where('committees.0.name', 'Comissão de Justiça')
                    ->where('committees.0.members_count', 1)
                    ->where('committees.0.documents_count', 1)
                    ->where('committees.0.president', $vereador->vereador_nome ?: $vereador->nome));

            $this->actingAs($user)
                ->get(route('committees.show', $comissao))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('committees/show')
                    ->where('canManage', false)
                    ->where('options', null)
                    ->has('members', 1)
                    ->where('members.0.id', $membro->id)
                    ->where('members.0.role', CommitteeRole::President->value)
                    ->where('members.0.office.id', $vereador->id)
                    ->has('documents', 1)
                    ->where('documents.0.id', $documento->id)
                    ->where('documents.0.link_type_label', 'Pauta'));

            // Ler não dá direito a gerenciar.
            $this->actingAs($user)
                ->put(route('committees.update', $comissao), $this->payload(['status' => 'ativa']))
                ->assertForbidden();
            $this->actingAs($user)
                ->get(route('committees.edit', $comissao))
                ->assertForbidden();
            $this->actingAs($user)
                ->delete(route('committees.members.destroy', [$comissao, $membro]))
                ->assertForbidden();
        }

        $this->actingAs($this->usuario($secretaria))
            ->get(route('committees.show', $comissao))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManage', true)
                ->has('options.offices', 1)
                ->where('options.offices.0.is_member', true)
                ->has('options.roles', 4)
                // O único documento da Câmara já está vinculado.
                ->has('options.documents', 0));
    }

    public function test_offices_of_another_city_council_get_forbidden_for_reading_and_writing(): void
    {
        $camara = $this->camara();
        $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $comissao = $this->comissao($camara);
        $membro = app(CommitteeMembershipService::class)
            ->add($comissao, $vereador->id, null, CommitteeRole::Member);
        $outraCamara = $this->camara();
        $outraSecretaria = $this->gabinete($outraCamara, OfficeType::LegislativeSecretariat);
        $vereadorDeFora = $this->gabinete($outraCamara, OfficeType::CouncilorOffice);
        $documentoDeFora = $this->documento($outraCamara);

        foreach ([$vereadorDeFora, $outraSecretaria] as $gabinete) {
            $user = $this->usuario($gabinete);

            $this->actingAs($user)
                ->get(route('committees.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('committees', 0));
            $this->actingAs($user)
                ->get(route('committees.show', $comissao))
                ->assertForbidden();
        }

        // A Secretaria de outra Câmara tem o módulo, mas não o registro.
        $outro = $this->usuario($outraSecretaria);
        $this->actingAs($outro)->get(route('committees.edit', $comissao))->assertForbidden();
        $this->actingAs($outro)
            ->put(route('committees.update', $comissao), $this->payload(['nome' => 'Invadida', 'status' => 'ativa']))
            ->assertForbidden();
        $this->actingAs($outro)->delete(route('committees.destroy', $comissao))->assertForbidden();
        $this->actingAs($outro)
            ->post(route('committees.members.store', $comissao), [
                'gabinete_id' => $vereadorDeFora->id,
                'cargo' => CommitteeRole::Member->value,
            ])
            ->assertForbidden();
        $this->actingAs($outro)
            ->delete(route('committees.members.destroy', [$comissao, $membro]))
            ->assertForbidden();
        $this->actingAs($outro)
            ->post(route('committees.documents.store', $comissao), ['documento_id' => $documentoDeFora->id])
            ->assertForbidden();

        $this->assertNotSoftDeleted($comissao);
        $this->assertSame($comissao->nome, $comissao->fresh()->nome);
        $this->assertDatabaseCount('comissao_membros', 1);
        $this->assertDatabaseCount('comissao_documentos', 0);
    }

    public function test_secretariat_manages_members_with_domain_rules(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $vereadorA = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $vereadorB = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $pessoaA = $this->usuario($vereadorA);
        $outraPessoaA = $this->usuario($vereadorA);
        $pessoaB = $this->usuario($vereadorB);
        $admin = $this->usuario($secretaria);
        $comissao = $this->comissao($camara);

        $this->actingAs($admin)
            ->post(route('committees.members.store', $comissao), [
                'gabinete_id' => $vereadorA->id,
                'usuario_id' => $pessoaA->id,
                'cargo' => CommitteeRole::President->value,
                'ingressou_em' => '2026-02-01',
            ])
            ->assertSessionHasNoErrors();

        $membro = ComissaoMembro::query()->sole();
        $this->assertSame($vereadorA->id, $membro->gabinete_id);
        $this->assertSame($pessoaA->id, $membro->usuario_id);
        $this->assertSame('2026-02-01', $membro->ingressou_em->toDateString());

        // O mesmo gabinete não entra duas vezes.
        $this->actingAs($admin)
            ->post(route('committees.members.store', $comissao), [
                'gabinete_id' => $vereadorA->id,
                'cargo' => CommitteeRole::Member->value,
            ])
            ->assertSessionHasErrors('gabinete_id');

        // Um presidente por comissão; a pessoa indicada é do próprio gabinete.
        $this->actingAs($admin)
            ->post(route('committees.members.store', $comissao), [
                'gabinete_id' => $vereadorB->id,
                'cargo' => CommitteeRole::President->value,
            ])
            ->assertSessionHasErrors('cargo');
        $this->actingAs($admin)
            ->post(route('committees.members.store', $comissao), [
                'gabinete_id' => $vereadorB->id,
                'usuario_id' => $pessoaA->id,
                'cargo' => CommitteeRole::Rapporteur->value,
            ])
            ->assertSessionHasErrors('usuario_id');
        $this->assertDatabaseCount('comissao_membros', 1);

        $this->actingAs($admin)
            ->post(route('committees.members.store', $comissao), [
                'gabinete_id' => $vereadorB->id,
                'usuario_id' => $pessoaB->id,
                'cargo' => CommitteeRole::Rapporteur->value,
            ])
            ->assertSessionHasNoErrors();

        // Trocar a pessoa indicada não duplica a linha; o gabinete não muda.
        $this->actingAs($admin)
            ->put(route('committees.members.update', [$comissao, $membro]), [
                'usuario_id' => $outraPessoaA->id,
                'cargo' => CommitteeRole::President->value,
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame($outraPessoaA->id, $membro->fresh()->usuario_id);
        $this->assertDatabaseCount('comissao_membros', 2);
        $this->actingAs($admin)
            ->put(route('committees.members.update', [$comissao, $membro]), [
                'gabinete_id' => $vereadorB->id,
                'cargo' => CommitteeRole::Member->value,
            ])
            ->assertSessionHasErrors('gabinete_id');

        $this->actingAs($admin)
            ->delete(route('committees.members.destroy', [$comissao, $membro]))
            ->assertRedirect();
        $this->assertDatabaseMissing('comissao_membros', ['id' => $membro->id]);

        // Membro de outra comissão não sai por esta URL.
        $outraComissao = $this->comissao($camara, ['nome' => 'Outra comissão']);
        $alheio = app(CommitteeMembershipService::class)
            ->add($outraComissao, $vereadorA->id, null, CommitteeRole::Member);
        $this->actingAs($admin)
            ->delete(route('committees.members.destroy', [$comissao, $alheio]))
            ->assertNotFound();
    }

    public function test_substitute_role_is_assignable_without_touching_the_single_president_rule(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $vereadorA = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $vereadorB = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $vereadorC = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $vereadorD = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $admin = $this->usuario($secretaria);
        $comissao = $this->comissao($camara);

        $this->actingAs($admin)
            ->get(route('committees.show', $comissao))
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.roles', fn (Collection $roles): bool => $roles
                    ->contains(fn (array $role): bool => $role['value'] === 'suplente' && $role['label'] === 'Suplente')));

        $this->actingAs($admin)
            ->post(route('committees.members.store', $comissao), [
                'gabinete_id' => $vereadorA->id,
                'cargo' => CommitteeRole::President->value,
            ])
            ->assertSessionHasNoErrors();

        // Mais de um suplente é permitido; o limite é só do presidente.
        foreach ([$vereadorB, $vereadorC] as $vereador) {
            $this->actingAs($admin)
                ->post(route('committees.members.store', $comissao), [
                    'gabinete_id' => $vereador->id,
                    'cargo' => CommitteeRole::Substitute->value,
                ])
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(2, ComissaoMembro::query()->where('cargo', 'suplente')->count());

        $this->actingAs($admin)
            ->post(route('committees.members.store', $comissao), [
                'gabinete_id' => $vereadorD->id,
                'cargo' => CommitteeRole::President->value,
            ])
            ->assertSessionHasErrors('cargo');

        // Suplente promovido a presidente esbarra no presidente que já existe.
        $suplente = ComissaoMembro::query()->where('gabinete_id', $vereadorB->id)->sole();
        $this->actingAs($admin)
            ->put(route('committees.members.update', [$comissao, $suplente]), [
                'cargo' => CommitteeRole::President->value,
            ])
            ->assertSessionHasErrors('cargo');
        $this->assertSame(CommitteeRole::Substitute, $suplente->fresh()->cargo);

        // Presidente rebaixado a suplente libera o cargo.
        $presidente = ComissaoMembro::query()->where('gabinete_id', $vereadorA->id)->sole();
        $this->actingAs($admin)
            ->put(route('committees.members.update', [$comissao, $presidente]), [
                'cargo' => CommitteeRole::Substitute->value,
            ])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->put(route('committees.members.update', [$comissao, $suplente]), [
                'cargo' => CommitteeRole::President->value,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get(route('committees.show', $comissao))
            ->assertInertia(fn (Assert $page) => $page
                ->where('members', fn (Collection $members): bool => $members
                    ->where('role', 'suplente')
                    ->pluck('role_label')
                    ->all() === ['Suplente', 'Suplente']));
    }

    public function test_only_active_councilor_offices_of_the_same_city_council_become_members(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $setor = $this->gabinete($camara, OfficeType::AdministrativeDepartment);
        $suspenso = Office::factory()->suspended()->create([
            'entidade_id' => $camara->id,
            'tipo_gabinete' => OfficeType::CouncilorOffice,
        ]);
        $deFora = $this->gabinete($this->camara(), OfficeType::CouncilorOffice);
        $comissao = $this->comissao($camara);

        foreach ([$setor, $suspenso, $deFora, $secretaria] as $gabinete) {
            $this->actingAs($this->usuario($secretaria))
                ->post(route('committees.members.store', $comissao), [
                    'gabinete_id' => $gabinete->id,
                    'cargo' => CommitteeRole::Member->value,
                ])
                ->assertSessionHasErrors('gabinete_id');
        }
        $this->assertDatabaseCount('comissao_membros', 0);

        // A regra vale fora da camada HTTP.
        $this->expectException(ValidationException::class);
        app(CommitteeMembershipService::class)->add($comissao, $deFora->id, null, CommitteeRole::Member);
    }

    public function test_extinct_committee_closes_memberships_and_rejects_new_members(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $vereadorA = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $vereadorB = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $admin = $this->usuario($secretaria);
        $comissao = $this->comissao($camara, ['nome' => 'CPI do transporte', 'tipo' => CommitteeType::Temporary]);
        $membro = app(CommitteeMembershipService::class)
            ->add($comissao, $vereadorA->id, null, CommitteeRole::Member);

        $this->actingAs($admin)
            ->put(route('committees.update', $comissao), $this->payload([
                'nome' => 'CPI do transporte',
                'tipo' => CommitteeType::Temporary->value,
                'status' => CommitteeStatus::Extinct->value,
            ]))
            ->assertRedirect(route('committees.show', $comissao));

        $comissao->refresh();
        $this->assertSame(CommitteeStatus::Extinct, $comissao->status);
        $this->assertNotNull($comissao->encerrada_em);
        $this->assertNotNull($membro->fresh()->saiu_em);

        $this->actingAs($admin)
            ->post(route('committees.members.store', $comissao), [
                'gabinete_id' => $vereadorB->id,
                'cargo' => CommitteeRole::Member->value,
            ])
            ->assertSessionHasErrors('gabinete_id');

        $this->actingAs($admin)
            ->put(route('committees.update', $comissao), $this->payload([
                'nome' => 'CPI do transporte',
                'status' => CommitteeStatus::Active->value,
            ]))
            ->assertRedirect();
        $this->assertNull($comissao->fresh()->encerrada_em);
        $this->assertNull($membro->fresh()->saiu_em);

        $this->actingAs($admin)
            ->delete(route('committees.destroy', $comissao))
            ->assertRedirect(route('committees.index'));
        $this->assertSoftDeleted($comissao);
    }

    public function test_secretariat_links_and_unlinks_existing_city_council_documents(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $admin = $this->usuario($secretaria);
        $comissao = $this->comissao($camara);
        $daCamara = $this->documento($camara, null, 'Pauta da reunião');
        $privadoDaSecretaria = $this->documento($camara, $secretaria);
        $privadoDoVereador = $this->documento($camara, $vereador);
        $daPlataforma = $this->documento(null);
        $deOutraCamara = $this->documento($this->camara());

        $this->actingAs($admin)
            ->get(route('committees.show', $comissao))
            ->assertInertia(fn (Assert $page) => $page
                ->has('options.documents', 1)
                ->where('options.documents.0.value', (string) $daCamara->id));

        // Só o compartilhado com a mesma Câmara pode ser vinculado.
        foreach ([$privadoDaSecretaria, $privadoDoVereador, $daPlataforma, $deOutraCamara] as $documento) {
            $this->actingAs($admin)
                ->post(route('committees.documents.store', $comissao), ['documento_id' => $documento->id])
                ->assertSessionHasErrors('documento_id');
        }

        $this->actingAs($admin)
            ->post(route('committees.documents.store', $comissao), [
                'documento_id' => $daCamara->id,
                'tipo_vinculo' => 'invalido',
            ])
            ->assertSessionHasErrors('tipo_vinculo');

        $this->actingAs($admin)
            ->post(route('committees.documents.store', $comissao), [
                'documento_id' => $daCamara->id,
                'tipo_vinculo' => 'parecer',
            ])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('comissao_documentos', [
            'comissao_id' => $comissao->id,
            'documento_id' => $daCamara->id,
            'tipo_vinculo' => 'parecer',
        ]);

        // O mesmo documento não é vinculado duas vezes.
        $this->actingAs($admin)
            ->post(route('committees.documents.store', $comissao), ['documento_id' => $daCamara->id])
            ->assertSessionHasErrors('documento_id');
        $this->assertDatabaseCount('comissao_documentos', 1);

        // O vereador lê, mas não desvincula.
        $this->actingAs($this->usuario($vereador))
            ->delete(route('committees.documents.destroy', [$comissao, $daCamara]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->delete(route('committees.documents.destroy', [$comissao, $daCamara]))
            ->assertRedirect();
        $this->assertDatabaseCount('comissao_documentos', 0);
        // Desvincular não apaga o documento da Base de Conhecimento.
        $this->assertNotSoftDeleted($daCamara);

        $this->actingAs($admin)
            ->delete(route('committees.documents.destroy', [$comissao, $daCamara]))
            ->assertNotFound();
    }

    public function test_database_unique_indexes_block_duplicate_members_and_links(): void
    {
        $camara = $this->camara();
        $vereador = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $comissao = $this->comissao($camara);
        $documento = $this->documento($camara);
        $now = now();

        DB::table('comissao_membros')->insert([
            'comissao_id' => $comissao->id,
            'gabinete_id' => $vereador->id,
            'cargo' => CommitteeRole::Member->value,
            'ingressou_em' => $now->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $comissao->documentos()->attach($documento->id);

        try {
            DB::table('comissao_membros')->insert([
                'comissao_id' => $comissao->id,
                'gabinete_id' => $vereador->id,
                'cargo' => CommitteeRole::Rapporteur->value,
                'ingressou_em' => $now->toDateString(),
            ]);
            $this->fail('O índice único de membros deveria recusar o gabinete repetido.');
        } catch (UniqueConstraintViolationException) {
        }

        $this->expectException(UniqueConstraintViolationException::class);
        $comissao->documentos()->attach($documento->id);
    }

    public function test_disabled_entidade_module_hides_committees(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $comissao = $this->comissao($camara);

        EntityModuleSubscription::query()
            ->where('entidade_id', $camara->id)
            ->where('modulo', EntityModule::LegislativeSecretariat->value)
            ->update(['ativo' => false]);

        foreach ([$vereador, $secretaria] as $gabinete) {
            $user = $this->usuario($gabinete);
            $this->actingAs($user)->get(route('committees.index'))->assertForbidden();
            $this->actingAs($user)->get(route('committees.show', $comissao))->assertForbidden();
        }
        $this->actingAs($this->usuario($secretaria))
            ->post(route('committees.store'), $this->payload())
            ->assertForbidden();

        // Office independente (sem o módulo na entidade) também não vê.
        $independente = Office::factory()->create();
        $this->actingAs($this->usuario($independente))
            ->get(route('committees.index'))
            ->assertForbidden();
    }

    public function test_secretariat_does_not_gain_access_to_councilor_private_documents_through_committees(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, OfficeType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, OfficeType::CouncilorOffice);
        $comissao = $this->comissao($camara);
        $privado = $this->documento($camara, $vereador, 'Minuta interna do vereador');

        $this->actingAs($this->usuario($secretaria))
            ->get(route('committees.show', $comissao))
            ->assertInertia(fn (Assert $page) => $page->has('options.documents', 0));
        $this->assertFalse($this->usuario($secretaria)->can('view', $privado));
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
        ]);
    }

    private function usuario(Office $gabinete): User
    {
        return User::factory()->administrator()->forGabinete($gabinete)->create();
    }

    /** @param  array<string, mixed>  $attributes */
    private function comissao(Entity $entidade, array $attributes = []): ComissaoLegislativa
    {
        $comissao = new ComissaoLegislativa;
        $comissao->forceFill([
            'entidade_id' => $entidade->id,
            'nome' => 'Comissão de Educação',
            'tipo' => CommitteeType::Permanent,
            'status' => CommitteeStatus::Active,
            'criado_por_id' => User::factory()->create()->id,
            ...$attributes,
        ])->save();

        return $comissao;
    }

    private function documento(?Entity $entidade, ?Office $gabinete = null, string $titulo = 'Documento'): KnowledgeDocument
    {
        $documento = new KnowledgeDocument;
        $documento->forceFill([
            'gabinete_id' => $gabinete?->id,
            'entidade_id' => $entidade?->id,
            'titulo' => $titulo,
            'disk' => 'local',
            'caminho' => 'conhecimento/'.uniqid().'.pdf',
            'nome_original' => 'documento.pdf',
            'tamanho' => 16,
        ])->save();

        return $documento;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'nome' => 'Comissão de Saúde',
            'sigla' => '',
            'tipo' => CommitteeType::Permanent->value,
            ...$overrides,
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Models\Demanda;
use App\Models\DemandaAnexo;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DemandCollaborationTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_is_recorded_as_a_timeline_event(): void
    {
        [$user, $demand] = $this->demandContext();

        $this->actingAs($user)
            ->post(route('demands.updates.store', $demand), [
                'texto' => 'Cidadão retornou com novas informações.',
            ])
            ->assertRedirect(route('demands.show', $demand));

        $this->assertDatabaseHas('demanda_eventos', [
            'gabinete_id' => $user->gabinete_id,
            'demanda_id' => $demand->id,
            'usuario_id' => $user->id,
            'tipo' => 'atualizacao',
            'descricao' => 'Cidadão retornou com novas informações.',
        ]);
        $this->assertNotNull($demand->fresh()->ultima_atividade_em);
    }

    public function test_update_cannot_be_added_to_another_office_demand(): void
    {
        [$user] = $this->demandContext();
        $foreignOffice = Gabinete::factory()->create();
        $foreignDemand = Demanda::factory()->forGabinete($foreignOffice)->create();

        $this->actingAs($user)
            ->post(route('demands.updates.store', $foreignDemand), [
                'texto' => 'Tentativa indevida.',
            ])
            ->assertNotFound();
    }

    public function test_valid_files_are_stored_privately_with_safe_names_and_audit(): void
    {
        Storage::fake('local');
        [$user, $demand] = $this->demandContext();

        $this->actingAs($user)
            ->post(route('demands.attachments.store', $demand), [
                'arquivos' => [
                    UploadedFile::fake()->image('foto da rua.jpg'),
                    UploadedFile::fake()->create('relatorio.pdf', 100, 'application/pdf'),
                ],
            ])
            ->assertRedirect(route('demands.show', $demand));

        $attachments = DemandaAnexo::query()->orderBy('id')->get();
        $this->assertCount(2, $attachments);

        foreach ($attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment->caminho);
            $this->assertStringStartsWith(
                "gabinetes/{$user->gabinete_id}/demandas/{$demand->id}/",
                $attachment->caminho,
            );
            $this->assertNotSame($attachment->nome_original, $attachment->nome_armazenado);
        }

        $this->assertSame(
            2,
            $demand->eventos()->where('tipo', 'anexo_adicionado')->count(),
        );
    }

    public function test_attachment_validation_rejects_excess_size_count_and_format(): void
    {
        Storage::fake('local');
        [$user, $demand] = $this->demandContext();
        $files = array_map(
            fn (int $index) => UploadedFile::fake()->image("foto-{$index}.jpg"),
            range(1, 6),
        );

        $this->actingAs($user)
            ->post(route('demands.attachments.store', $demand), ['arquivos' => $files])
            ->assertSessionHasErrors('arquivos');

        $this->post(route('demands.attachments.store', $demand), [
            'arquivos' => [UploadedFile::fake()->create('grande.pdf', 10241, 'application/pdf')],
        ])->assertSessionHasErrors('arquivos.0');

        $this->post(route('demands.attachments.store', $demand), [
            'arquivos' => [UploadedFile::fake()->create('script.php', 1, 'text/x-php')],
        ])->assertSessionHasErrors('arquivos.0');

        $this->assertDatabaseCount('demanda_anexos', 0);
    }

    public function test_private_attachment_can_only_be_downloaded_by_its_office(): void
    {
        Storage::fake('local');
        [$owner, $demand] = $this->demandContext();

        $this->actingAs($owner)->post(route('demands.attachments.store', $demand), [
            'arquivos' => [UploadedFile::fake()->create('relatorio.pdf', 20, 'application/pdf')],
        ]);
        $attachment = DemandaAnexo::query()->firstOrFail();

        $this->get(route('demands.attachments.download', [$demand, $attachment]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $otherOffice = Gabinete::factory()->create();
        $otherUser = User::factory()->advisor()->forGabinete($otherOffice)->create();

        $this->actingAs($otherUser)
            ->get(route('demands.attachments.download', [$demand, $attachment]))
            ->assertNotFound();
    }

    public function test_attachment_removal_deletes_private_file_and_creates_audit(): void
    {
        Storage::fake('local');
        [$user, $demand] = $this->demandContext();

        $this->actingAs($user)->post(route('demands.attachments.store', $demand), [
            'arquivos' => [UploadedFile::fake()->image('antes.jpg')],
        ]);
        $attachment = DemandaAnexo::query()->firstOrFail();
        $path = $attachment->caminho;

        $this->delete(route('demands.attachments.destroy', [$demand, $attachment]))
            ->assertRedirect(route('demands.show', $demand));

        Storage::disk('local')->assertMissing($path);
        $this->assertSoftDeleted('demanda_anexos', ['id' => $attachment->id]);
        $this->assertDatabaseHas('demanda_eventos', [
            'demanda_id' => $demand->id,
            'tipo' => 'anexo_removido',
        ]);
    }

    public function test_show_does_not_expose_physical_attachment_metadata(): void
    {
        Storage::fake('local');
        [$user, $demand] = $this->demandContext();

        $this->actingAs($user)->post(route('demands.attachments.store', $demand), [
            'arquivos' => [UploadedFile::fake()->image('foto.jpg')],
        ]);

        $this->get(route('demands.show', $demand))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('demand.anexos', 1)
                ->missing('demand.anexos.0.disk')
                ->missing('demand.anexos.0.caminho')
                ->missing('demand.anexos.0.nome_armazenado'));
    }

    public function test_priority_responsible_and_deadline_changes_have_specific_timeline_events(): void
    {
        [$user, $demand] = $this->demandContext();
        $responsible = User::factory()->advisor()->forGabinete($user->gabinete)->create();

        $this->actingAs($user)
            ->put(route('demands.update', $demand), [
                'cidadao_id' => $demand->cidadao_id,
                'titulo' => $demand->titulo,
                'descricao' => $demand->descricao,
                'categoria_id' => $demand->categoria_id,
                'bairro_id' => $demand->bairro_id,
                'responsavel_id' => $responsible->id,
                'prioridade' => 'urgente',
                'origem' => $demand->origem->value,
                'prazo' => now()->addDays(15)->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('demands.show', $demand));

        foreach (['prioridade_alterada', 'responsavel_alterado', 'prazo_alterado'] as $tipo) {
            $this->assertDatabaseHas('demanda_eventos', [
                'demanda_id' => $demand->id,
                'tipo' => $tipo,
            ]);
        }
    }

    /** @return array{User, Demanda} */
    private function demandContext(): array
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->advisor()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office, creator: $user)->create();

        return [$user, $demand];
    }
}

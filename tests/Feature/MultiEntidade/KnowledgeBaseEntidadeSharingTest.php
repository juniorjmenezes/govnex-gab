<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\EntidadeModule;
use App\Enums\EntidadeType;
use App\Enums\GabineteType;
use App\Models\ConhecimentoDocumento;
use App\Models\Entidade;
use App\Models\EntidadeModulo;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Base de Conhecimento em três níveis: do gabinete, compartilhado com a
 * Câmara pela Secretaria legislativa e da plataforma.
 */
class KnowledgeBaseEntidadeSharingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    public function test_secretariat_shares_a_document_with_the_whole_city_council(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $servidor = User::factory()->operator()->forGabinete($secretaria)->create();

        $this->actingAs($servidor)
            ->get('/conhecimento')
            ->assertInertia(fn (Assert $page) => $page->where('canShareWithEntidade', true));

        $this->actingAs($servidor)
            ->post('/conhecimento', [
                'titulo' => 'Regimento interno da Câmara',
                'arquivo' => $this->pdf('regimento.pdf'),
                'compartilhar_entidade' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $document = ConhecimentoDocumento::query()->sole();
        $this->assertNull($document->gabinete_id);
        $this->assertSame($camara->id, $document->entidade_id);
        $this->assertTrue($document->isEntidadeWide());
        $this->assertStringStartsWith("entidades/{$camara->id}/conhecimento/", $document->caminho);
        Storage::disk('local')->assertExists($document->caminho);
    }

    public function test_without_the_flag_the_secretariat_document_stays_private(): void
    {
        $camara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);

        $this->actingAs($this->usuario($secretaria))
            ->post('/conhecimento', [
                'titulo' => 'Rascunho interno',
                'arquivo' => $this->pdf('rascunho.pdf'),
            ])
            ->assertSessionHasNoErrors();

        $document = ConhecimentoDocumento::query()->sole();
        $this->assertSame($secretaria->id, $document->gabinete_id);
        // A entidade fica registrada só para consistência; não amplia a visibilidade.
        $this->assertSame($camara->id, $document->entidade_id);
        $this->assertTrue($document->isGabinete());
    }

    public function test_only_the_secretariat_with_its_module_can_share_with_the_entidade(): void
    {
        $camara = $this->camara();
        $vereador = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $auditor = User::factory()->auditor()->forGabinete($secretaria)->create();

        $this->actingAs($this->usuario($vereador))
            ->get('/conhecimento')
            ->assertInertia(fn (Assert $page) => $page->where('canShareWithEntidade', false));
        $this->actingAs($this->usuario($vereador))
            ->post('/conhecimento', $this->payload())
            ->assertForbidden();
        $this->actingAs($auditor)
            ->post('/conhecimento', $this->payload())
            ->assertForbidden();

        // Com a Secretaria legislativa desligada na Câmara, nem ela compartilha.
        EntidadeModulo::query()
            ->where('entidade_id', $camara->id)
            ->where('modulo', EntidadeModule::LegislativeSecretariat->value)
            ->update(['ativo' => false]);
        app()->forgetScopedInstances();

        $this->actingAs($this->usuario($secretaria))
            ->post('/conhecimento', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('conhecimento_documentos', 0);
    }

    public function test_entidade_document_reaches_every_office_of_the_same_city_council_only(): void
    {
        $camara = $this->camara();
        $outraCamara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $vereadorA = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $vereadorB = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $vereadorDeFora = $this->gabinete($outraCamara, GabineteType::CouncilorOffice);
        $platform = $this->document(title: 'Manual da plataforma');
        $shared = $this->document(entidade: $camara, title: 'Regimento interno');
        $privateA = $this->document(office: $vereadorA, title: 'Nota do gabinete A');
        $this->document(office: $vereadorB, title: 'Nota do gabinete B');
        $this->document(entidade: $outraCamara, title: 'Regimento da outra Câmara');

        $this->actingAs($this->usuario($vereadorA))
            ->get('/conhecimento')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 3)
                ->where('documents.data.0.id', $privateA->id)
                ->where('documents.data.0.origin', 'gabinete')
                ->where('documents.data.0.origin_label', null)
                ->where('documents.data.1.id', $shared->id)
                ->where('documents.data.1.origin', 'entidade')
                ->where('documents.data.1.origin_label', GabineteType::LegislativeSecretariat->label())
                ->where('documents.data.2.id', $platform->id)
                ->where('documents.data.2.origin', 'plataforma'));

        foreach ([$vereadorB, $secretaria] as $gabinete) {
            $this->actingAs($this->usuario($gabinete))
                ->get("/conhecimento/{$shared->id}/arquivo")
                ->assertOk();
        }

        $estranho = $this->usuario($vereadorDeFora);
        $this->actingAs($estranho)
            ->get('/conhecimento')
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 2)
                ->where('documents.data.1.id', $platform->id));
        $this->actingAs($estranho)->get("/conhecimento/{$shared->id}")->assertForbidden();
        $this->actingAs($estranho)->get("/conhecimento/{$shared->id}/arquivo")->assertForbidden();
        $this->actingAs($this->usuario($vereadorB))
            ->get("/conhecimento/{$privateA->id}/arquivo")
            ->assertForbidden();
    }

    public function test_entidade_documents_leave_the_library_while_the_module_is_off_in_the_entidade(): void
    {
        $camara = $this->camara();
        $vereador = $this->usuario($this->gabinete($camara, GabineteType::CouncilorOffice));
        $platform = $this->document();
        $shared = $this->document(entidade: $camara);
        EntidadeModulo::query()
            ->where('entidade_id', $camara->id)
            ->where('modulo', EntidadeModule::LegislativeSecretariat->value)
            ->update(['ativo' => false]);
        app()->forgetScopedInstances();

        $this->actingAs($vereador)
            ->get('/conhecimento')
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.id', $platform->id));
        $this->actingAs($vereador)->get("/conhecimento/{$shared->id}/arquivo")->assertForbidden();
    }

    public function test_entidade_document_is_removed_only_by_the_uploader_or_a_secretariat_manager(): void
    {
        $camara = $this->camara();
        $outraCamara = $this->camara();
        $secretaria = $this->gabinete($camara, GabineteType::LegislativeSecretariat);
        $outraSecretaria = $this->gabinete($outraCamara, GabineteType::LegislativeSecretariat);
        $vereador = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $autor = User::factory()->operator()->forGabinete($secretaria)->create();
        $colega = User::factory()->operator()->forGabinete($secretaria)->create();
        $chefe = $this->usuario($secretaria);
        $chefeDoVereador = $this->usuario($vereador);
        $mine = $this->document(entidade: $camara, uploader: $autor);
        $theirs = $this->document(entidade: $camara, uploader: $colega);

        $this->actingAs($chefeDoVereador)->delete("/conhecimento/{$mine->id}")->assertForbidden();
        $this->actingAs($this->usuario($outraSecretaria))->delete("/conhecimento/{$mine->id}")->assertForbidden();
        $this->actingAs($colega)->delete("/conhecimento/{$mine->id}")->assertForbidden();

        $this->actingAs($chefeDoVereador)
            ->get('/conhecimento')
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.data.0.can_delete', false)
                ->where('documents.data.1.can_delete', false));

        $this->actingAs($autor)->delete("/conhecimento/{$mine->id}")->assertRedirect();
        $this->actingAs($chefe)->delete("/conhecimento/{$theirs->id}")->assertRedirect();

        $this->assertSoftDeleted($mine);
        $this->assertSoftDeleted($theirs);
        Storage::disk('local')->assertMissing($mine->caminho);
    }

    public function test_platform_admin_library_does_not_list_entidade_documents(): void
    {
        $root = User::factory()->root()->create();
        $platform = $this->document();
        $shared = $this->document(entidade: $this->camara());

        $this->actingAs($root)
            ->get('/admin/conhecimento')
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.id', $platform->id));
        $this->actingAs($root)->get("/admin/conhecimento/{$shared->id}")->assertNotFound();
        $this->actingAs($root)->delete("/admin/conhecimento/{$shared->id}")->assertNotFound();
    }

    public function test_backfill_fills_the_owner_entidade_without_widening_visibility(): void
    {
        $camara = $this->camara();
        $vereadorA = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $vereadorB = $this->gabinete($camara, GabineteType::CouncilorOffice);
        $legacy = $this->document(office: $vereadorA);
        $platform = $this->document();
        DB::table('conhecimento_documentos')->where('id', $legacy->id)->update(['entidade_id' => null]);

        $migration = require database_path('migrations/2026_09_17_000001_create_base_conhecimento_tables.php');
        $migration->backfillDocumentEntidade();
        $migration->backfillDocumentEntidade();

        $legacy->refresh();
        $this->assertSame($vereadorA->id, $legacy->gabinete_id);
        $this->assertSame($camara->id, $legacy->entidade_id);
        $this->assertTrue($legacy->isGabinete());
        $this->assertNull($platform->refresh()->entidade_id);
        $this->assertTrue($platform->isPlatform());

        $this->assertSame(
            [$legacy->id, $platform->id],
            ConhecimentoDocumento::query()->visibleTo($vereadorA->id, $camara->id)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            [$platform->id],
            ConhecimentoDocumento::query()->visibleTo($vereadorB->id, $camara->id)->pluck('id')->all(),
        );
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
        ]);
    }

    private function usuario(Gabinete $gabinete): User
    {
        return User::factory()->administrator()->forGabinete($gabinete)->create();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'titulo' => 'Pauta da sessão',
            'arquivo' => $this->pdf('pauta.pdf'),
            'compartilhar_entidade' => '1',
        ];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n",
        );
    }

    private function document(
        ?Gabinete $office = null,
        ?Entidade $entidade = null,
        string $title = 'Documento',
        ?User $uploader = null,
    ): ConhecimentoDocumento {
        $path = 'conhecimento/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\n%%EOF\n");
        $document = new ConhecimentoDocumento;
        $document->forceFill([
            'gabinete_id' => $office?->id,
            'entidade_id' => $office->entidade_id ?? $entidade?->id,
            'titulo' => $title,
            'disk' => 'local',
            'caminho' => $path,
            'nome_original' => 'documento.pdf',
            'tamanho' => 16,
            'enviado_por_id' => $uploader?->id,
            'created_at' => now()->addSeconds(ConhecimentoDocumento::query()->count()),
        ])->save();

        return $document;
    }
}

<?php

namespace Tests\Feature;

use App\Enums\GabineteModule;
use App\Models\ConhecimentoDocumento;
use App\Models\ConhecimentoLeitura;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    public function test_office_member_uploads_a_pdf_to_the_office_library(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();

        $this->actingAs($advisor)
            ->post('/conhecimento', [
                'titulo' => 'Regimento interno',
                'descricao' => 'Versão consolidada',
                'arquivo' => $this->pdf('regimento.pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $document = ConhecimentoDocumento::query()->sole();
        $this->assertSame($office->id, $document->gabinete_id);
        $this->assertSame($advisor->id, $document->enviado_por_id);
        $this->assertSame('regimento.pdf', $document->nome_original);
        $this->assertStringStartsWith("gabinetes/{$office->id}/conhecimento/", $document->caminho);
        Storage::disk('local')->assertExists($document->caminho);
    }

    public function test_upload_rejects_files_that_are_not_pdfs(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();

        $this->actingAs($advisor)
            ->post('/conhecimento', [
                'titulo' => 'Planilha disfarçada',
                'arquivo' => UploadedFile::fake()->createWithContent('dados.pdf', "nome,valor\nA,1\n"),
            ])
            ->assertSessionHasErrors('arquivo');

        $this->actingAs($advisor)
            ->post('/conhecimento', [
                'titulo' => 'Imagem',
                'arquivo' => UploadedFile::fake()->image('foto.png'),
            ])
            ->assertSessionHasErrors('arquivo');

        $this->assertDatabaseCount('conhecimento_documentos', 0);
    }

    public function test_library_lists_platform_and_own_documents_only(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();
        $platform = $this->document(null, 'Manual da plataforma');
        $own = $this->document($office, 'Documento do gabinete');
        $this->document($otherOffice, 'Documento de outro gabinete');

        $this->actingAs($advisor)
            ->get('/conhecimento')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('knowledge/index')
                ->where('scope', 'gabinete')
                ->has('documents.data', 2)
                ->where('documents.data.0.id', $own->id)
                ->where('documents.data.0.origin', 'gabinete')
                ->where('documents.data.1.id', $platform->id)
                ->where('documents.data.1.origin', 'plataforma'));
    }

    public function test_file_is_served_inline_only_to_who_can_see_it(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();
        $own = $this->document($office);
        $platform = $this->document(null);
        $foreign = $this->document($otherOffice);

        $this->actingAs($advisor)
            ->get("/conhecimento/{$own->id}/arquivo")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($advisor)
            ->get("/conhecimento/{$platform->id}/arquivo")
            ->assertOk();
        $this->actingAs($advisor)
            ->get("/conhecimento/{$foreign->id}/arquivo")
            ->assertForbidden();
        $this->actingAs($advisor)
            ->get("/conhecimento/{$foreign->id}")
            ->assertForbidden();
    }

    public function test_module_must_be_active_for_the_office(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();
        DB::table('gabinete_modulos')
            ->where('gabinete_id', $office->id)
            ->where('modulo', GabineteModule::KnowledgeBase->value)
            ->update(['ativo' => false]);

        $this->actingAs($advisor)->get('/conhecimento')->assertForbidden();
    }

    public function test_reading_completes_once_per_person_and_counts_globally(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();
        $chief = User::factory()->chiefOfStaff()->forGabinete($office)->create();
        $document = $this->document(null);

        foreach ([1, 2, 2] as $page) {
            $this->read($advisor, $document, $page, 3)->assertSessionHasNoErrors();
        }

        $reading = ConhecimentoLeitura::query()->where('usuario_id', $advisor->id)->sole();
        $this->assertSame([1, 2], $reading->paginas_lidas);
        $this->assertNull($reading->concluida_em);
        $this->assertSame(3, $document->fresh()->total_paginas);
        $this->assertSame(0, $document->fresh()->leituras_completas);

        $this->read($advisor, $document, 3, 3);
        $this->assertNotNull($reading->fresh()->concluida_em);
        $this->assertSame(1, $document->fresh()->leituras_completas);

        // Reler não conta de novo para a mesma pessoa.
        $this->read($advisor, $document, 1, 3);
        $this->assertSame(1, $document->fresh()->leituras_completas);

        foreach ([3, 1, 2] as $page) {
            $this->read($chief, $document, $page, 3);
        }
        $this->assertSame(2, $document->fresh()->leituras_completas);

        $this->actingAs($advisor)
            ->get("/conhecimento/{$document->id}")
            ->assertOk()
            ->assertJsonPath('document.progress.percent', 100)
            ->assertJsonPath('document.progress.pages_read', [1, 2, 3])
            ->assertJsonPath('document.completed_readings', 2);
    }

    public function test_page_beyond_the_known_total_is_rejected(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();
        $document = $this->document($office);
        $document->forceFill(['total_paginas' => 2])->save();

        $this->read($advisor, $document, 5, 9)->assertSessionHasErrors('pagina');
        $this->assertDatabaseCount('conhecimento_leituras', 0);
    }

    public function test_office_documents_are_removed_by_the_uploader_or_a_manager(): void
    {
        $office = Gabinete::factory()->create();
        $author = User::factory()->advisor()->forGabinete($office)->create();
        $colleague = User::factory()->advisor()->forGabinete($office)->create();
        $chief = User::factory()->chiefOfStaff()->forGabinete($office)->create();
        $mine = $this->document($office, uploader: $author);
        $theirs = $this->document($office, uploader: $colleague);
        $platform = $this->document(null);

        $this->actingAs($colleague)->delete("/conhecimento/{$mine->id}")->assertForbidden();
        $this->actingAs($colleague)->delete("/conhecimento/{$platform->id}")->assertForbidden();
        $this->actingAs($author)->delete("/conhecimento/{$mine->id}")->assertRedirect();
        $this->actingAs($chief)->delete("/conhecimento/{$theirs->id}")->assertRedirect();

        $this->assertSoftDeleted($mine);
        $this->assertSoftDeleted($theirs);
        $this->assertNotSoftDeleted($platform);
        Storage::disk('local')->assertMissing($mine->caminho);
    }

    public function test_platform_admin_manages_the_platform_library(): void
    {
        $root = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();

        $this->actingAs($advisor)->get('/admin/conhecimento')->assertForbidden();

        $this->actingAs($root)
            ->post('/admin/conhecimento', [
                'titulo' => 'Cartilha eleitoral',
                'arquivo' => $this->pdf('cartilha.pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.knowledge.index'));

        $document = ConhecimentoDocumento::query()->sole();
        $this->assertNull($document->gabinete_id);
        $this->assertStringStartsWith('conhecimento/plataforma/', $document->caminho);

        $officeDocument = $this->document($office);
        $this->actingAs($root)
            ->get('/admin/conhecimento')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('knowledge/index')
                ->where('scope', 'plataforma')
                ->has('documents.data', 1));
        $this->actingAs($root)->get("/admin/conhecimento/{$officeDocument->id}")->assertNotFound();
        $this->actingAs($root)->get("/admin/conhecimento/{$document->id}/arquivo")->assertOk();

        $this->actingAs($root)
            ->delete("/admin/conhecimento/{$document->id}")
            ->assertRedirect(route('admin.knowledge.index'));
        $this->assertSoftDeleted($document);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n",
        );
    }

    private function document(?Gabinete $office, string $title = 'Documento', ?User $uploader = null): ConhecimentoDocumento
    {
        $path = ($office ? "gabinetes/{$office->id}" : 'conhecimento/plataforma').'/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\n%%EOF\n");
        $document = new ConhecimentoDocumento;
        $document->forceFill([
            'gabinete_id' => $office?->id,
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

    private function read(User $user, ConhecimentoDocumento $document, int $page, int $total): TestResponse
    {
        return $this->actingAs($user)
            ->from("/conhecimento/{$document->id}")
            ->post("/conhecimento/{$document->id}/leitura", [
                'pagina' => $page,
                'total_paginas' => $total,
            ]);
    }
}

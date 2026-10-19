<?php

namespace Tests\Feature\Admin;

use App\Enums\WhatsAppPurpose;
use App\Models\Entidade;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ProvisionsEntidadeWhatsApp;
use Tests\TestCase;

class WhatsAppAdminTest extends TestCase
{
    use ProvisionsEntidadeWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * `Entidade::getRouteKeyName()` é `slug`, não `id` — as rotas
     * `whatsapp/entidades/{entidade}/...` só resolvem com o slug na URL.
     * `selectedEntidade.id` usado nessas URLs (em vez de `.slug`) 404ava
     * sempre, porque a comparação cai para a coluna `slug`, não `id`.
     */
    public function test_index_exposes_the_entidade_slug_needed_for_the_connection_routes(): void
    {
        $root = User::factory()->root()->create();
        $entidade = Entidade::factory()->create();
        $gabinete = Gabinete::factory()->create(['entidade_id' => $entidade->id]);

        $this->actingAs($root)
            ->get(route('admin.whatsapp.index', ['gabinete_id' => $gabinete->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/whatsapp/index')
                ->where('selectedEntidade.id', $entidade->id)
                ->where('selectedEntidade.slug', $entidade->slug));
    }

    public function test_consult_accounts_resolves_by_entidade_slug(): void
    {
        $root = User::factory()->root()->create();
        $entidade = Entidade::factory()->create();

        $this->actingAs($root)
            ->post("/admin/whatsapp/entidades/{$entidade->slug}/contas/consultar")
            ->assertRedirect();
    }

    /** O id numérico nunca é um slug válido: a rota deve recusar, não 500. */
    public function test_consult_accounts_404s_for_a_numeric_id_instead_of_the_slug(): void
    {
        $root = User::factory()->root()->create();
        $entidade = Entidade::factory()->create();

        $this->actingAs($root)
            ->post("/admin/whatsapp/entidades/{$entidade->id}/contas/consultar")
            ->assertNotFound();
    }

    /**
     * Sem segredo do gateway configurado (padrão em dev), a tentativa de
     * consultar contas falha com uma mensagem amigável, não uma exceção não
     * tratada.
     */
    public function test_consult_accounts_without_gateway_secret_flashes_a_friendly_error(): void
    {
        config(['whatsapp.request_secret' => null]);
        $root = User::factory()->root()->create();
        $entidade = Entidade::factory()->create();

        $this->actingAs($root)
            ->post("/admin/whatsapp/entidades/{$entidade->slug}/contas/consultar")
            ->assertRedirect();

        $flash = session('inertia.flash_data', []);
        $this->assertSame('error', $flash['toast']['type'] ?? null);
    }

    /**
     * `WhatsAppTemplateService::createDraft()` recusa criar rascunho quando
     * a finalidade já tem versão no gateway — uma `RuntimeException` de
     * regra de negócio, não de infraestrutura. Antes da correção, nenhuma
     * ação de template do controller capturava isso: a tela quebrava com
     * 500 em vez de mostrar o aviso já escrito no serviço.
     */
    public function test_create_template_flashes_a_friendly_error_instead_of_crashing(): void
    {
        $root = User::factory()->root()->create();
        $entidade = Entidade::factory()->create();
        $office = Gabinete::factory()->create(['entidade_id' => $entidade->id]);
        $purpose = WhatsAppPurpose::DemandAssigned;
        $this->readyEntidadeWhatsAppTemplate($office, $purpose);

        $this->actingAs($root)
            ->post(route('admin.whatsapp.templates.store'), [
                'entidade_id' => $entidade->id,
                'purpose' => $purpose->value,
            ])
            ->assertRedirect();

        $flash = session('inertia.flash_data', []);
        $this->assertSame('error', $flash['toast']['type'] ?? null);
        $this->assertStringContainsString('já possui uma versão', (string) ($flash['toast']['message'] ?? ''));
    }

    /**
     * Mesma proteção na ativação de finalidade: `setActive(true)` recusa um
     * template que ainda não foi aprovado no gateway (`RuntimeException` de
     * regra de negócio, igual ao teste acima).
     */
    public function test_activate_template_status_flashes_a_friendly_error_for_an_unapproved_template(): void
    {
        $root = User::factory()->root()->create();
        $entidade = Entidade::factory()->create();
        $office = Gabinete::factory()->create(['entidade_id' => $entidade->id]);
        $template = $this->readyEntidadeWhatsAppTemplate($office, WhatsAppPurpose::DemandAssigned);
        $template->forceFill(['gateway_template_id' => null, 'ativo' => false])->save();

        $this->actingAs($root)
            ->patch(route('admin.whatsapp.templates.status', $template), ['active' => true])
            ->assertRedirect();

        $flash = session('inertia.flash_data', []);
        $this->assertSame('error', $flash['toast']['type'] ?? null);
        $this->assertStringContainsString('não está disponível no gateway', (string) ($flash['toast']['message'] ?? ''));
    }
}

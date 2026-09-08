<?php

namespace Tests\Feature\Admin;

use App\Models\IntegracaoGovnexApi;
use App\Models\User;
use App\Services\Politics\Tse\GovnexApiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GovnexApiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_sees_the_env_defaults_before_anything_is_saved(): void
    {
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->get('/admin/integracoes/govnex-api')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/integrations/govnex-api')
                ->where('integration.url', config('services.govnex_api.url'))
                ->where('integration.from_env', true)
                ->where('integration.has_key', false)
            );
    }

    public function test_a_non_root_user_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/integracoes/govnex-api')
            ->assertForbidden();

        $this->actingAs($user)
            ->put('/admin/integracoes/govnex-api', ['url' => 'https://exemplo.test/api/v1'])
            ->assertForbidden();
    }

    public function test_root_saves_the_url_and_key_and_the_key_is_encrypted_at_rest(): void
    {
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->put('/admin/integracoes/govnex-api', [
                'url' => 'https://api.exemplo.test/api/v1/',
                'chave' => 'gnx_chave_de_teste_com_tamanho',
            ])
            ->assertRedirect();

        $record = IntegracaoGovnexApi::query()->firstOrFail();

        // A barra final é normalizada para não duplicar ao concatenar rotas.
        $this->assertSame('https://api.exemplo.test/api/v1', $record->url);
        $this->assertSame('gnx_chave_de_teste_com_tamanho', $record->chave);
        $this->assertSame($admin->id, $record->atualizado_por_id);

        $raw = DB::table('integracao_govnex_api')->value('chave');
        $this->assertNotSame('gnx_chave_de_teste_com_tamanho', $raw);
    }

    public function test_the_key_is_never_sent_back_to_the_browser(): void
    {
        $admin = User::factory()->root()->create();
        IntegracaoGovnexApi::create([
            'url' => 'https://api.exemplo.test/api/v1',
            'chave' => 'gnx_chave_de_teste_com_tamanho',
        ]);

        $this->actingAs($admin)
            ->get('/admin/integracoes/govnex-api')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('integration.has_key', true)
                ->where('integration.key_hint', '…amanho')
                ->missing('integration.chave')
            )
            ->assertDontSee('gnx_chave_de_teste_com_tamanho');
    }

    public function test_an_empty_key_field_keeps_the_stored_key(): void
    {
        $admin = User::factory()->root()->create();
        IntegracaoGovnexApi::create([
            'url' => 'https://antiga.test/api/v1',
            'chave' => 'gnx_chave_de_teste_com_tamanho',
        ]);

        $this->actingAs($admin)
            ->put('/admin/integracoes/govnex-api', [
                'url' => 'https://nova.test/api/v1',
                'chave' => '',
            ])
            ->assertRedirect();

        $record = IntegracaoGovnexApi::query()->firstOrFail();
        $this->assertSame('https://nova.test/api/v1', $record->url);
        $this->assertSame('gnx_chave_de_teste_com_tamanho', $record->chave);
    }

    public function test_the_key_can_be_removed_explicitly(): void
    {
        $admin = User::factory()->root()->create();
        IntegracaoGovnexApi::create([
            'url' => 'https://api.exemplo.test/api/v1',
            'chave' => 'gnx_chave_de_teste_com_tamanho',
        ]);

        $this->actingAs($admin)
            ->put('/admin/integracoes/govnex-api', [
                'url' => 'https://api.exemplo.test/api/v1',
                'chave' => '',
                'remover_chave' => true,
            ])
            ->assertRedirect();

        $this->assertNull(IntegracaoGovnexApi::query()->firstOrFail()->chave);
    }

    public function test_the_saved_configuration_overrides_the_env_for_the_api_client(): void
    {
        IntegracaoGovnexApi::create([
            'url' => 'https://salvo.test/api/v1',
            'chave' => 'gnx_chave_de_teste_com_tamanho',
        ]);

        $settings = app(GovnexApiSettings::class);

        $this->assertSame('https://salvo.test/api/v1', $settings->url());
        $this->assertSame('gnx_chave_de_teste_com_tamanho', $settings->key());
    }

    public function test_testing_the_connection_records_a_rejected_key(): void
    {
        $admin = User::factory()->root()->create();
        IntegracaoGovnexApi::create([
            'url' => 'https://api.exemplo.test/api/v1',
            'chave' => 'gnx_chave_de_teste_com_tamanho',
        ]);

        Http::fake([
            'api.exemplo.test/api/v1/sources' => Http::response(['message' => 'API key inválida.'], 401),
        ]);

        $this->actingAs($admin)
            ->post('/admin/integracoes/govnex-api/testar')
            ->assertRedirect();

        $record = IntegracaoGovnexApi::query()->firstOrFail();
        $this->assertSame('chave_invalida', $record->verificado_resultado);
        $this->assertNotNull($record->verificada_em);
    }

    public function test_testing_the_connection_records_success_with_the_source_count(): void
    {
        $admin = User::factory()->root()->create();
        IntegracaoGovnexApi::create([
            'url' => 'https://api.exemplo.test/api/v1',
            'chave' => 'gnx_chave_de_teste_com_tamanho',
        ]);

        Http::fake([
            'api.exemplo.test/api/v1/sources' => Http::response([
                'data' => [['id' => 1, 'slug' => 'tse'], ['id' => 2, 'slug' => 'govnex']],
            ]),
        ]);

        $this->actingAs($admin)
            ->post('/admin/integracoes/govnex-api/testar')
            ->assertRedirect();

        $record = IntegracaoGovnexApi::query()->firstOrFail();
        $this->assertSame('ok', $record->verificado_resultado);
        $this->assertStringContainsString('2 fonte(s)', (string) $record->verificado_detalhe);
    }

    public function test_the_url_is_validated(): void
    {
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->put('/admin/integracoes/govnex-api', ['url' => 'nao-e-url'])
            ->assertSessionHasErrors('url');
    }
}

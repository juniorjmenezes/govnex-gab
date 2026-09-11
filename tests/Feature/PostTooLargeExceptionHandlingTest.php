<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Um upload maior que post_max_size nunca chega ao controller — o PHP
 * descarta o corpo da requisição e o Laravel lança PostTooLargeException
 * ainda no grupo de middlewares globais (ValidatePostSize), antes do
 * StartSession do grupo "web" sequer rodar (não há sessão disponível aqui).
 * O handler dessa exceção em bootstrap/app.php precisa devolver algo que o
 * Inertia aceite; uma resposta JSON pura quebra o protocolo ("All Inertia
 * requests must receive a valid Inertia response...").
 */
class PostTooLargeExceptionHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_inertia_request_gets_plain_html_instead_of_raw_json(): void
    {
        $admin = User::factory()->root()->create();

        $response = $this->actingAs($admin)->call(
            'POST',
            '/admin/sincronizacoes-tse-globais/govnex-api',
            server: [
                'CONTENT_LENGTH' => 5 * 1024 * 1024 * 1024,
                'HTTP_X_INERTIA' => 'true',
            ],
        );

        $response->assertStatus(413);
        $this->assertNull($response->headers->get('X-Inertia'));
        $this->assertStringNotContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertSeeText('O conteúdo enviado excede o limite de '.ini_get('post_max_size').' aceito pelo servidor.');
    }

    public function test_plain_json_api_client_still_gets_a_json_error(): void
    {
        $admin = User::factory()->root()->create();

        $response = $this->actingAs($admin)->call(
            'POST',
            '/admin/sincronizacoes-tse-globais/govnex-api',
            server: [
                'CONTENT_LENGTH' => 5 * 1024 * 1024 * 1024,
                'HTTP_ACCEPT' => 'application/json',
            ],
        );

        $response->assertStatus(413);
        $response->assertJsonStructure(['message', 'errors' => ['arquivo']]);
    }
}

<?php

namespace Tests\Feature;

use App\Services\Politics\Tse\GovnexApiClient;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * As falhas da GOVNEX API vão parar no status da sincronização, na tela —
 * a mensagem precisa dizer o que aconteceu e o que fazer, nunca despejar o
 * corpo da resposta.
 */
class GovnexApiClientTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regressão: `latest_import_status` nulo era tratado como importado, e a
     * leitura dos registros estourava com o 409 cru da API.
     */
    public function test_a_dataset_registered_without_any_import_is_not_read_and_says_what_to_do(): void
    {
        $this->fakeCatalog(['slug' => 'municipio-tse-ibge', 'latest_import_status' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('O dataset municipio-tse-ibge está cadastrado na GOVNEX API, mas ainda não tem nenhum arquivo importado. Importe nele o arquivo do TSE e sincronize de novo.');

        app(TsePoliticalDataSyncService::class)->importMunicipalities();
    }

    public function test_a_dataset_still_being_imported_asks_to_wait(): void
    {
        $this->fakeCatalog(['slug' => 'municipio-tse-ibge', 'latest_import_status' => 'importing']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('O dataset municipio-tse-ibge ainda está sendo importado na GOVNEX API.');

        app(TsePoliticalDataSyncService::class)->importMunicipalities();
    }

    public function test_a_dataset_whose_last_import_failed_says_so(): void
    {
        $this->fakeCatalog(['slug' => 'municipio-tse-ibge', 'latest_import_status' => 'failed']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A última importação do dataset municipio-tse-ibge na GOVNEX API falhou.');

        app(TsePoliticalDataSyncService::class)->importMunicipalities();
    }

    /**
     * Mesmo que o dataset perca o arquivo entre a localização e a leitura, o
     * 409 vira a mesma frase — sem JSON, sem exception do Symfony, e sem
     * repetir a consulta, que daria a mesma resposta.
     */
    public function test_a_409_while_reading_records_becomes_a_readable_message_without_retrying(): void
    {
        Http::fake([
            '127.0.0.1:8020/api/v1/sources/tse/datasets/consulta-cand-2024/records*' => Http::response([
                'message' => 'Dataset ainda não possui uma versão importada.',
                'exception' => 'Symfony\\Component\\HttpKernel\\Exception\\HttpException',
            ], 409),
        ]);

        $message = $this->failureOf(fn () => app(GovnexApiClient::class)->eachRecord(
            'tse',
            'consulta-cand-2024',
            function (array $row): void {},
        ));

        $this->assertSame(
            'O dataset consulta-cand-2024 está cadastrado na GOVNEX API, mas ainda não tem nenhum arquivo importado. Importe nele o arquivo do TSE e sincronize de novo.',
            $message,
        );
        Http::assertSentCount(1);
    }

    public function test_a_rate_limit_asks_to_wait(): void
    {
        Http::fake(['127.0.0.1:8020/api/v1/sources' => Http::response(['message' => 'Too Many Attempts.'], 429)]);

        $message = $this->failureOf(fn () => app(GovnexApiClient::class)->locate('municipalities'));

        $this->assertStringContainsString('A GOVNEX API limitou o número de consultas', $message);
    }

    public function test_an_unreachable_api_names_the_configured_address(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::failedConnection()]);

        $message = $this->failureOf(fn () => app(GovnexApiClient::class)->locate('municipalities'));

        $this->assertSame(
            'Não foi possível conectar à GOVNEX API em http://127.0.0.1:8020/api/v1. Confira se ela está no ar e se GOVNEX_API_URL aponta para o endereço certo.',
            $message,
        );
    }

    public function test_an_unexpected_status_keeps_only_the_reason_given_by_the_api(): void
    {
        Http::fake([
            '127.0.0.1:8020/api/v1/sources/tse/datasets/votacao-secao-2024-ce/records*' => Http::response([
                'message' => 'Filtros não permitidos: SG_UF',
                'exception' => 'Illuminate\\Validation\\ValidationException',
                'trace' => [['file' => '/var/www/app.php']],
            ], 422),
        ]);

        $message = $this->failureOf(fn () => app(GovnexApiClient::class)->count('tse', 'votacao-secao-2024-ce'));

        $this->assertSame(
            'A GOVNEX API recusou a consulta ao dataset votacao-secao-2024-ce (HTTP 422). Motivo informado: Filtros não permitidos: SG_UF',
            $message,
        );
    }

    private function failureOf(callable $call): string
    {
        try {
            $call();
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        $this->fail('A chamada deveria ter falhado.');
    }

    /** @param array<string, mixed> $dataset */
    private function fakeCatalog(array $dataset): void
    {
        Http::fake([
            '127.0.0.1:8020/api/v1/sources' => Http::response(['data' => [['slug' => 'tse']]]),
            '127.0.0.1:8020/api/v1/sources/tse/datasets' => Http::response(['data' => [$dataset]]),
        ]);
    }
}

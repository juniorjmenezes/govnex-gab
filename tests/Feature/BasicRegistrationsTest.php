<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Bairro;
use App\Models\Categoria;
use App\Models\Cidadao;
use App\Models\Gabinete;
use App\Models\User;
use App\Services\Geocoding\GeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BasicRegistrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_advisor_can_register_citizen_only_in_own_office(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();
        $neighborhood = Bairro::factory()->forGabinete($office)->create();
        $foreignNeighborhood = Bairro::factory()->forGabinete($otherOffice)->create();

        $this->actingAs($advisor)->post(route('citizens.store'), [
            'nome' => 'Maria da Silva',
            'cpf' => '',
            'telefone' => '(85) 99999-0000',
            'bairro_id' => $neighborhood->id,
            'latitude' => -3.731862,
            'longitude' => -38.526669,
            'localizacao_origem' => 'endereco',
            'consentimento_contato' => true,
            'eleitor' => true,
            'gabinete_id' => $otherOffice->id,
        ])->assertRedirect();

        $citizen = Cidadao::withoutGlobalScopes()->sole();
        $this->assertSame($office->id, $citizen->gabinete_id);
        $this->assertSame('85999990000', $citizen->telefone);
        $this->assertNull($citizen->cpf);
        $this->assertTrue($citizen->eleitor);
        $this->assertSame('-3.7318620', $citizen->latitude);
        $this->assertSame('-38.5266690', $citizen->longitude);
        $this->assertSame('endereco', $citizen->localizacao_origem);

        $this->actingAs($advisor)->post(route('citizens.store'), [
            'nome' => 'Cadastro inválido',
            'bairro_id' => $foreignNeighborhood->id,
            'consentimento_contato' => false,
            'eleitor' => false,
        ])->assertSessionHasErrors('bairro_id');
    }

    public function test_advisor_can_search_an_address_for_citizen_location(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();
        Http::fake([
            '*' => Http::response([
                [
                    'display_name' => 'Rua Exemplo, 123, Fortaleza, Ceará, Brasil',
                    'lat' => '-3.7318620',
                    'lon' => '-38.5266690',
                    'address' => ['house_number' => '123'],
                ],
            ]),
        ]);

        $this->actingAs($advisor)
            ->getJson(route('citizens.location.search', [
                'logradouro' => 'Rua Exemplo',
                'numero' => '123',
                'bairro' => 'Centro',
                'municipio' => 'Fortaleza',
                'estado' => 'CE',
            ]))
            ->assertOk()
            ->assertExactJson([
                'results' => [
                    [
                        'label' => 'Rua Exemplo, 123, Fortaleza, Ceará, Brasil',
                        'latitude' => -3.731862,
                        'longitude' => -38.526669,
                        'precision' => 'address',
                    ],
                ],
            ]);

        Http::assertSent(fn ($request): bool => $request['street'] === '123 Rua Exemplo'
            && $request['city'] === 'Fortaleza'
            && $request['state'] === 'CE'
            && ! isset($request['q'])
            && $request['countrycodes'] === 'br'
            && ! str_contains($request->url(), $advisor->name));
    }

    public function test_geocoding_falls_back_to_street_when_number_is_not_found(): void
    {
        config(['services.geocoding.minimum_interval_ms' => 0]);

        Http::fakeSequence()
            ->push([], 200)
            ->push([], 200)
            ->push([
                [
                    'display_name' => 'Rua Exemplo, Centro, Fortaleza, Ceará, Brasil',
                    'lat' => '-3.7318620',
                    'lon' => '-38.5266690',
                ],
            ], 200);

        $results = app(GeocodingService::class)->search(
            street: 'Rua Exemplo',
            number: '9999',
            neighborhood: 'Centro',
            city: 'Fortaleza',
            state: 'CE',
        );

        $this->assertSame('street', $results[0]['precision']);
        $this->assertSame(-3.731862, $results[0]['latitude']);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => ($request['street'] ?? null) === 'Rua Exemplo'
            && ($request['city'] ?? null) === 'Fortaleza'
            && ! isset($request['q']));
    }

    /**
     * O Nominatim responde com o logradouro inteiro quando não conhece a
     * numeração. O ponto é aproveitável, mas precisa chegar como
     * "logradouro" — rotulá-lo de "endereço" fazia o cadastro afirmar uma
     * exatidão que o marcador não tinha.
     */
    public function test_result_without_house_number_is_reported_as_street(): void
    {
        config(['services.geocoding.minimum_interval_ms' => 0]);
        Http::fake([
            '*' => Http::response([
                [
                    'display_name' => 'Rua Exemplo, Centro, Fortaleza, Ceará, Brasil',
                    'lat' => '-3.7318620',
                    'lon' => '-38.5266690',
                    'address' => ['road' => 'Rua Exemplo'],
                ],
            ], 200),
        ]);

        $results = app(GeocodingService::class)->search(
            street: 'Rua Exemplo',
            number: '9999',
            neighborhood: 'Centro',
            city: 'Fortaleza',
            state: 'CE',
        );

        $this->assertSame('street', $results[0]['precision']);
    }

    /** O CEP é a consulta com maior chance de cravar o número no Brasil. */
    public function test_postal_code_is_used_before_the_city_and_state_query(): void
    {
        config(['services.geocoding.minimum_interval_ms' => 0]);
        Http::fake([
            '*' => Http::response([
                [
                    'display_name' => 'Rua Exemplo, 123, Fortaleza, Ceará, Brasil',
                    'lat' => '-3.7318620',
                    'lon' => '-38.5266690',
                    'address' => ['house_number' => '123'],
                ],
            ], 200),
        ]);

        $results = app(GeocodingService::class)->search(
            street: 'Rua Exemplo',
            number: '123',
            neighborhood: 'Centro',
            city: 'Fortaleza',
            state: 'CE',
            postalCode: '60000-000',
        );

        $this->assertSame('address', $results[0]['precision']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => ($request['postalcode'] ?? null) === '60000000'
            && ($request['street'] ?? null) === '123 Rua Exemplo');
    }

    public function test_geocoding_does_not_cache_empty_results(): void
    {
        config(['services.geocoding.minimum_interval_ms' => 0]);
        Http::fake(fn () => Http::response([], 200));
        $geocoding = app(GeocodingService::class);

        $firstResults = $geocoding->search(
            street: 'Rua Sem Resultado',
            number: '10',
            neighborhood: 'Centro',
            city: 'Cidade de Teste',
            state: 'CE',
        );
        $secondResults = $geocoding->search(
            street: 'Rua Sem Resultado',
            number: '10',
            neighborhood: 'Centro',
            city: 'Cidade de Teste',
            state: 'CE',
        );

        $this->assertSame([], $firstResults);
        $this->assertSame([], $secondResults);
        Http::assertSentCount(10);
    }

    public function test_citizen_location_requires_both_coordinates(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();

        $this->actingAs($advisor)->post(route('citizens.store'), [
            'nome' => 'Localização incompleta',
            'latitude' => -3.731862,
            'consentimento_contato' => false,
            'eleitor' => true,
        ])->assertSessionHasErrors('longitude');
    }

    public function test_advisor_can_update_citizen_voter_status(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();
        $citizen = Cidadao::factory()->forGabinete($office)->create([
            'eleitor' => true,
            'latitude' => -3.731862,
            'longitude' => -38.526669,
            'localizacao_origem' => 'endereco',
        ]);

        $this->actingAs($advisor)->put(route('citizens.update', $citizen), [
            'nome' => $citizen->nome,
            'consentimento_contato' => $citizen->consentimento_contato,
            'eleitor' => false,
        ])->assertRedirect(route('citizens.show', $citizen));

        $updatedCitizen = $citizen->fresh();
        $this->assertFalse($updatedCitizen->eleitor);
        $this->assertNull($updatedCitizen->latitude);
        $this->assertNull($updatedCitizen->longitude);
        $this->assertNull($updatedCitizen->localizacao_origem);
    }

    public function test_possible_duplicate_warns_but_does_not_block_registration(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();
        Cidadao::factory()->forGabinete($office)->create(['telefone' => '85988887777']);

        $response = $this->actingAs($advisor)->post(route('citizens.store'), [
            'nome' => 'Pessoa semelhante',
            'telefone' => '(85) 98888-7777',
            'consentimento_contato' => true,
        ]);

        $response->assertRedirect()->assertSessionHas('possible_duplicates');
        $this->assertSame(2, Cidadao::withoutGlobalScopes()->count());
    }

    public function test_cpf_is_unique_per_office_but_may_repeat_between_offices(): void
    {
        $first = Gabinete::factory()->create();
        $second = Gabinete::factory()->create();
        $cpf = '12345678901';
        Cidadao::factory()->forGabinete($first)->create(['cpf' => $cpf]);

        $this->actingAs(User::factory()->operator()->forGabinete($first)->create())
            ->post(route('citizens.store'), [
                'nome' => 'Duplicado local',
                'cpf' => $cpf,
                'consentimento_contato' => false,
            ])->assertSessionHasErrors('cpf');

        $this->actingAs(User::factory()->operator()->forGabinete($second)->create())
            ->post(route('citizens.store'), [
                'nome' => 'Cadastro permitido',
                'cpf' => $cpf,
                'consentimento_contato' => false,
            ])->assertRedirect();
    }

    public function test_only_office_managers_can_manage_categories_and_neighborhoods(): void
    {
        $office = Gabinete::factory()->create();
        $chief = User::factory()->administrator()->forGabinete($office)->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();

        $this->actingAs($chief)->post(route('categories.store'), [
            'nome' => 'Saúde',
            'descricao' => 'Atendimentos de saúde',
            'icone' => 'heart-pulse',
            'cor_semantica' => 'critica',
            'ativo' => true,
        ])->assertRedirect(route('categories.index'))
            ->assertInertiaFlash('toast', [
                'type' => 'success',
                'message' => 'Categoria cadastrada.',
            ]);

        $this->actingAs($chief)->post(route('neighborhoods.store'), [
            'nome' => 'Centro',
            'municipio' => 'Município adulterado',
            'estado' => 'SP',
            'ativo' => true,
        ])->assertRedirect(route('neighborhoods.index'))
            ->assertInertiaFlash('toast', [
                'type' => 'success',
                'message' => 'Bairro cadastrado.',
            ]);

        $this->assertSame($office->id, Categoria::withoutGlobalScopes()->sole()->gabinete_id);
        $neighborhood = Bairro::withoutGlobalScopes()->sole();
        $this->assertSame($office->municipio, $neighborhood->municipio);
        $this->assertSame($office->estado, $neighborhood->estado);

        $this->actingAs($advisor)->post(route('categories.store'), [
            'nome' => 'Não permitido',
            'cor_semantica' => 'neutra',
            'ativo' => true,
        ])->assertForbidden();
    }

    public function test_team_management_respects_roles_and_office_boundary(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $chief = User::factory()->administrator()->forGabinete($office)->create();
        $foreignUser = User::factory()->operator()->forGabinete($otherOffice)->create();

        $this->actingAs($chief)->post(route('team.store'), [
            'name' => 'Nova Assessora',
            'email' => 'nova@example.test',
            'role' => UserRole::Operator->value,
            'password' => 'Senha123!',
            'password_confirmation' => 'Senha123!',
        ])->assertRedirect(route('team.index'));

        $member = User::query()->where('email', 'nova@example.test')->sole();
        $this->assertSame($office->id, $member->gabinete_id);

        $this->actingAs($chief)->post(route('team.store'), [
            'name' => 'Papel inexistente',
            'email' => 'papel@example.test',
            'role' => 'vereador',
            'password' => 'Senha123!',
            'password_confirmation' => 'Senha123!',
        ])->assertSessionHasErrors('role');

        $this->actingAs($member)->post(route('team.store'), [
            'name' => 'Cadastro por operador',
            'email' => 'operador@example.test',
            'role' => UserRole::Operator->value,
            'password' => 'Senha123!',
            'password_confirmation' => 'Senha123!',
        ])->assertForbidden();

        $this->actingAs($chief)->put(route('team.update', $foreignUser), [
            'name' => 'Tentativa externa',
            'email' => $foreignUser->email,
            'role' => UserRole::Operator->value,
            'is_active' => false,
        ])->assertNotFound();

        $this->actingAs($chief)->put(route('team.password.update', $member), [
            'password' => 'NovaSenha123!',
            'password_confirmation' => 'NovaSenha123!',
        ])->assertRedirect(route('team.index'));
        $this->assertTrue(Hash::check('NovaSenha123!', $member->fresh()->password));
    }
}

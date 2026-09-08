<?php

namespace Tests\Feature;

use App\Models\Cidadao;
use App\Models\Gabinete;
use App\Models\User;
use App\Services\Citizens\CitizenDuplicateFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CitizenDuplicateFinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_empty_when_no_field_is_provided(): void
    {
        $office = Gabinete::factory()->create();
        Cidadao::factory()->forGabinete($office)->create(['cpf' => '11111111111']);
        $this->actingAs(User::factory()->advisor()->forGabinete($office)->create());

        $matches = (new CitizenDuplicateFinder)->find([]);

        $this->assertTrue($matches->isEmpty());
    }

    public function test_it_matches_by_cpf(): void
    {
        $office = Gabinete::factory()->create();
        $existing = Cidadao::factory()->forGabinete($office)->create([
            'nome' => 'Maria Existente',
            'cpf' => '11111111111',
        ]);
        $this->actingAs(User::factory()->advisor()->forGabinete($office)->create());

        $matches = (new CitizenDuplicateFinder)->find(['cpf' => '11111111111']);

        $this->assertCount(1, $matches);
        $this->assertSame($existing->id, $matches->first()['id']);
        $this->assertSame(['CPF'], $matches->first()['matches']);
    }

    public function test_it_reports_every_field_that_matched(): void
    {
        $office = Gabinete::factory()->create();
        $existing = Cidadao::factory()->forGabinete($office)->create([
            'cpf' => '22222222222',
            'telefone' => '85999990000',
            'whatsapp' => '85988880000',
            'email' => 'duplicado@exemplo.test',
        ]);
        $this->actingAs(User::factory()->advisor()->forGabinete($office)->create());

        $matches = (new CitizenDuplicateFinder)->find([
            'cpf' => '22222222222',
            'telefone' => '85999990000',
            'whatsapp' => 'não-bate',
            'email' => 'duplicado@exemplo.test',
        ]);

        $this->assertCount(1, $matches);
        $this->assertSame(['CPF', 'telefone', 'e-mail'], $matches->first()['matches']);
    }

    public function test_it_excludes_the_ignored_citizen(): void
    {
        $office = Gabinete::factory()->create();
        $self = Cidadao::factory()->forGabinete($office)->create(['cpf' => '33333333333']);
        $this->actingAs(User::factory()->advisor()->forGabinete($office)->create());

        $matches = (new CitizenDuplicateFinder)->find(['cpf' => '33333333333'], $self);

        $this->assertTrue($matches->isEmpty());
    }

    public function test_it_never_matches_citizens_from_another_office(): void
    {
        // CitizenDuplicateFinder não filtra gabinete_id por conta própria —
        // depende inteiramente do global scope de TenantModel (ver
        // BelongsToGabinete), aplicado a partir do usuário autenticado no
        // contexto. Este teste protege contra uma regressão que remova esse
        // scope (ex.: trocar Cidadao::query() por uma consulta que o
        // ignore) e vazar dados privados entre gabinetes.
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        Cidadao::factory()->forGabinete($otherOffice)->create(['cpf' => '44444444444']);
        $this->actingAs(User::factory()->advisor()->forGabinete($office)->create());

        $matches = (new CitizenDuplicateFinder)->find(['cpf' => '44444444444']);

        $this->assertTrue($matches->isEmpty());
    }
}

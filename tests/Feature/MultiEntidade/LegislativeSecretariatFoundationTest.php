<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\EntidadeModule;
use App\Enums\EntidadeType;
use App\Enums\GabineteModule;
use App\Enums\GabineteType;
use App\Models\Entidade;
use App\Models\EntidadeModulo;
use App\Models\Gabinete;
use App\Models\GabineteModulo;
use App\Models\User;
use App\Services\Entidades\EstruturaProvisioningService;
use App\Services\Modules\EntidadeModuleManager;
use App\Services\Modules\GabineteModuleCatalog;
use App\Services\Modules\GabineteModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class LegislativeSecretariatFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETARIAT_MODULES = [
        'AGENDA',
        'WHATSAPP',
        'BASE_CONHECIMENTO',
        'SECRETARIA_LEGISLATIVA',
    ];

    public function test_city_council_creates_a_legislative_secretariat(): void
    {
        $camara = $this->entidade(EntidadeType::CityCouncil);

        $secretaria = $this->criarGabinete($camara, GabineteType::LegislativeSecretariat);

        $this->assertSame(GabineteType::LegislativeSecretariat, $secretaria->tipo_gabinete);
        $this->assertSame('Secretaria legislativa', $secretaria->tipo_gabinete->label());
        $this->assertSame('Secretário legislativo', $secretaria->tipo_gabinete->leaderLabel());
        $this->assertDatabaseHas('gabinete_modulos', [
            'gabinete_id' => $secretaria->id,
            'modulo' => GabineteModule::LegislativeSecretariat->value,
            'ativo' => true,
        ]);
    }

    public function test_city_council_accepts_only_one_legislative_secretariat(): void
    {
        $camara = $this->entidade(EntidadeType::CityCouncil);
        $this->criarGabinete($camara, GabineteType::LegislativeSecretariat);

        try {
            $this->criarGabinete($camara, GabineteType::LegislativeSecretariat, 'Outra secretaria');
            $this->fail('A segunda Secretaria legislativa deveria ser recusada.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('A entidade já tem uma Secretaria legislativa.', $e->getMessage());
        }

        // Gabinetes de vereador continuam livres, e outra Câmara tem a sua.
        $this->criarGabinete($camara, GabineteType::CouncilorOffice, 'Gabinete do vereador');
        $this->criarGabinete($this->entidade(EntidadeType::CityCouncil), GabineteType::LegislativeSecretariat);

        $this->assertSame(1, Gabinete::withoutGlobalScopes()
            ->where('entidade_id', $camara->id)
            ->where('tipo_gabinete', GabineteType::LegislativeSecretariat)
            ->count());
    }

    public function test_soft_deleted_secretariat_still_blocks_a_new_one(): void
    {
        $camara = $this->entidade(EntidadeType::CityCouncil);
        $this->criarGabinete($camara, GabineteType::LegislativeSecretariat)->delete();

        $this->expectException(InvalidArgumentException::class);

        $this->criarGabinete($camara, GabineteType::LegislativeSecretariat, 'Outra secretaria');
    }

    public function test_other_entidade_types_reject_the_legislative_secretariat(): void
    {
        foreach ([EntidadeType::CityHall, EntidadeType::IndependentOffice] as $tipo) {
            try {
                $this->criarGabinete($this->entidade($tipo), GabineteType::LegislativeSecretariat);
                $this->fail("{$tipo->value} não deveria aceitar Secretaria legislativa.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('A entidade não aceita este tipo de gabinete.', $e->getMessage());
            }
        }

        $this->assertFalse(Gabinete::withoutGlobalScopes()
            ->where('tipo_gabinete', GabineteType::LegislativeSecretariat)
            ->exists());
    }

    public function test_entidade_module_is_contracted_but_off_only_for_city_councils(): void
    {
        $camara = $this->entidade(EntidadeType::CityCouncil);

        $this->assertDatabaseHas('entidade_modulos', [
            'entidade_id' => $camara->id,
            'modulo' => EntidadeModule::LegislativeSecretariat->value,
            'contratado' => true,
            'ativo' => false,
        ]);

        foreach ([EntidadeType::CityHall, EntidadeType::IndependentOffice] as $tipo) {
            $this->assertDatabaseMissing('entidade_modulos', [
                'entidade_id' => $this->entidade($tipo)->id,
                'modulo' => EntidadeModule::LegislativeSecretariat->value,
            ]);
        }
    }

    public function test_module_is_effective_only_after_the_city_council_turns_it_on(): void
    {
        $camara = $this->entidade(EntidadeType::CityCouncil);
        $secretaria = $this->criarGabinete($camara, GabineteType::LegislativeSecretariat);
        $vereador = $this->criarGabinete($camara, GabineteType::CouncilorOffice, 'Gabinete do vereador');

        $this->assertFalse(app(GabineteModuleManager::class)->isActive($secretaria, GabineteModule::LegislativeSecretariat));

        app(EntidadeModuleManager::class)->syncActivation(
            $camara,
            [...app(EntidadeModuleManager::class)->activeFor($camara), EntidadeModule::LegislativeSecretariat->value],
            User::factory()->root()->create(),
        );

        // O cache de módulos vale por requisição; a próxima começa limpa.
        app()->forgetScopedInstances();
        $modules = app(GabineteModuleManager::class);
        $this->assertTrue($modules->isActive($secretaria->fresh(), GabineteModule::LegislativeSecretariat));
        // Mesmo com a Câmara ligada, o vereador não ganha a gestão da Secretaria.
        $this->assertFalse($modules->isActive($vereador->fresh(), GabineteModule::LegislativeSecretariat));
    }

    public function test_only_the_secretariat_gabinete_can_enable_the_module(): void
    {
        $camara = $this->entidade(EntidadeType::CityCouncil);
        $vereador = $this->criarGabinete($camara, GabineteType::CouncilorOffice, 'Gabinete do vereador');
        $modules = app(GabineteModuleManager::class);

        $this->assertNotContains(GabineteModule::LegislativeSecretariat->value, $modules->allEnabled());
        $this->assertNotContains(GabineteModule::LegislativeSecretariat->value, $modules->allEnabled(GabineteType::CouncilorOffice));
        $this->assertContains(GabineteModule::LegislativeSecretariat->value, $modules->allEnabled(GabineteType::LegislativeSecretariat));
        $this->assertDatabaseMissing('gabinete_modulos', [
            'gabinete_id' => $vereador->id,
            'modulo' => GabineteModule::LegislativeSecretariat->value,
            'ativo' => true,
        ]);

        $this->expectException(ValidationException::class);

        $modules->sync($vereador, [
            ...$modules->allEnabled(),
            GabineteModule::LegislativeSecretariat->value,
        ], null);
    }

    public function test_module_requires_schedule_and_knowledge_base_on_both_levels(): void
    {
        $camara = $this->entidade(EntidadeType::CityCouncil);
        $root = User::factory()->root()->create();
        $entidadeModules = app(EntidadeModuleManager::class);

        foreach ([
            [EntidadeModule::LegislativeSecretariat->value, EntidadeModule::Schedule->value],
            [EntidadeModule::LegislativeSecretariat->value, EntidadeModule::KnowledgeBase->value],
        ] as $selection) {
            try {
                $entidadeModules->syncActivation($camara, $selection, $root);
                $this->fail('A entidade não deveria ativar a Secretaria sem Agenda e Base de Conhecimento.');
            } catch (ValidationException $e) {
                $this->assertSame(
                    'Secretaria legislativa exige Agenda e Base de Conhecimento.',
                    $e->errors()['modules'][0],
                );
            }
        }

        $this->assertSame(
            [EntidadeModule::Schedule->value, EntidadeModule::KnowledgeBase->value, EntidadeModule::LegislativeSecretariat->value],
            $entidadeModules->validateActivation($camara, [
                EntidadeModule::LegislativeSecretariat->value,
                EntidadeModule::KnowledgeBase->value,
                EntidadeModule::Schedule->value,
            ]),
        );

        $secretaria = $this->criarGabinete($camara, GabineteType::LegislativeSecretariat);

        try {
            app(GabineteModuleManager::class)->sync($secretaria, [
                GabineteModule::Schedule->value,
                GabineteModule::LegislativeSecretariat->value,
            ], null);
            $this->fail('O gabinete não deveria ativar a Secretaria sem Base de Conhecimento.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Base de Conhecimento', $e->errors()['modules'][0]);
        }
    }

    public function test_secretariat_type_offers_only_its_own_modules(): void
    {
        $catalog = app(GabineteModuleCatalog::class);

        $this->assertSame(self::SECRETARIAT_MODULES, array_column(
            $catalog->availableFor(GabineteType::LegislativeSecretariat),
            'value',
        ));

        // Os demais tipos seguem com o catálogo de sempre, sem a gestão da Secretaria.
        $default = array_values(array_diff(
            $catalog->values(),
            [GabineteModule::LegislativeSecretariat->value],
        ));
        $this->assertSame($default, array_column($catalog->availableFor(null), 'value'));
        foreach (GabineteType::cases() as $tipo) {
            if ($tipo !== GabineteType::LegislativeSecretariat) {
                $this->assertSame($default, array_column($catalog->availableFor($tipo), 'value'), $tipo->value);
            }
        }
    }

    public function test_new_secretariat_starts_with_only_its_modules(): void
    {
        $secretaria = $this->criarGabinete($this->entidade(EntidadeType::CityCouncil), GabineteType::LegislativeSecretariat);

        $this->assertSame(
            self::SECRETARIAT_MODULES,
            app(GabineteModuleManager::class)->allEnabled(GabineteType::LegislativeSecretariat),
        );
        $this->assertEqualsCanonicalizing(
            self::SECRETARIAT_MODULES,
            GabineteModulo::query()->where('gabinete_id', $secretaria->id)->where('ativo', true)
                ->pluck('modulo')->map(fn (GabineteModule $module): string => $module->value)->all(),
        );
        // Os indisponíveis nem ganham linha desligada.
        $this->assertSame(
            count(self::SECRETARIAT_MODULES),
            GabineteModulo::query()->where('gabinete_id', $secretaria->id)->count(),
        );
    }

    public function test_secretariat_rejects_modules_unavailable_for_its_type(): void
    {
        $secretaria = $this->criarGabinete($this->entidade(EntidadeType::CityCouncil), GabineteType::LegislativeSecretariat);
        $modules = app(GabineteModuleManager::class);

        try {
            $modules->sync($secretaria, [
                ...self::SECRETARIAT_MODULES,
                GabineteModule::Relationship->value,
                GabineteModule::Demands->value,
                GabineteModule::Attendances->value,
                GabineteModule::Events->value,
                GabineteModule::Politics->value,
                GabineteModule::Reports->value,
            ], null);
            $this->fail('A Secretaria legislativa não deveria aceitar módulos de atendimento ao cidadão nem de política.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'Este tipo de gabinete não aceita: Relacionamento, Demandas, Atendimentos, Eventos, Inteligência política, Relatórios.',
                $e->errors()['modules'][0],
            );
        }

        $this->assertDatabaseMissing('gabinete_modulos', [
            'gabinete_id' => $secretaria->id,
            'modulo' => GabineteModule::Demands->value,
        ]);
    }

    public function test_secretariat_uses_schedule_and_whatsapp_without_relationship(): void
    {
        $secretaria = $this->criarGabinete($this->entidade(EntidadeType::CityCouncil), GabineteType::LegislativeSecretariat);
        $modules = app(GabineteModuleManager::class);

        $modules->sync($secretaria, [GabineteModule::Schedule->value, GabineteModule::WhatsApp->value], null);

        $this->assertSame(
            [GabineteModule::Schedule->value, GabineteModule::WhatsApp->value],
            $modules->activeFor($secretaria),
        );

        $definitions = app(GabineteModuleCatalog::class)->definitions(GabineteType::LegislativeSecretariat);
        $this->assertSame([], $definitions[GabineteModule::Schedule->value]['dependencies']);
        $this->assertSame([GabineteModule::Schedule->value], $definitions[GabineteModule::WhatsApp->value]['any_of']);

        try {
            $modules->sync($secretaria, [GabineteModule::WhatsApp->value], null);
            $this->fail('O WhatsApp da Secretaria deveria exigir a Agenda.');
        } catch (ValidationException $e) {
            $this->assertSame('WhatsApp exige ao menos um destes módulos: Agenda.', $e->errors()['modules'][0]);
        }
    }

    public function test_other_types_keep_schedule_depending_on_relationship(): void
    {
        $vereador = $this->criarGabinete($this->entidade(EntidadeType::CityCouncil), GabineteType::CouncilorOffice, 'Gabinete do vereador');

        $this->assertSame(
            [GabineteModule::Relationship->value],
            app(GabineteModuleCatalog::class)->definitions(GabineteType::CouncilorOffice)[GabineteModule::Schedule->value]['dependencies'],
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Agenda exige Relacionamento.');

        app(GabineteModuleManager::class)->sync($vereador, [GabineteModule::Schedule->value], null);
    }

    public function test_sync_turns_off_leftover_rows_of_modules_the_type_no_longer_accepts(): void
    {
        $secretaria = $this->criarGabinete($this->entidade(EntidadeType::CityCouncil), GabineteType::LegislativeSecretariat);
        // Linha gravada antes da restrição do tipo.
        (new GabineteModulo)->forceFill([
            'gabinete_id' => $secretaria->id,
            'modulo' => GabineteModule::Demands->value,
            'ativo' => true,
            'ativado_em' => now(),
        ])->save();
        $modules = app(GabineteModuleManager::class);

        $modules->sync($secretaria, self::SECRETARIAT_MODULES, null);

        $this->assertNotContains(GabineteModule::Demands->value, $modules->activeFor($secretaria));
        $this->assertDatabaseHas('gabinete_modulos', [
            'gabinete_id' => $secretaria->id,
            'modulo' => GabineteModule::Demands->value,
            'ativo' => false,
        ]);
        $this->assertDatabaseHas('gabinete_modulo_eventos', [
            'gabinete_id' => $secretaria->id,
            'modulo' => GabineteModule::Demands->value,
            'acao' => 'DESATIVADO',
        ]);
    }

    public function test_backfill_migration_contracts_the_module_off_only_for_existing_city_councils(): void
    {
        $camara = $this->entidade(EntidadeType::CityCouncil);
        $prefeitura = $this->entidade(EntidadeType::CityHall);
        $independente = $this->entidade(EntidadeType::IndependentOffice);
        EntidadeModulo::query()->where('modulo', EntidadeModule::LegislativeSecretariat->value)->delete();

        $migration = require database_path('migrations/2026_10_14_000001_enable_secretaria_legislativa_module.php');
        $migration->up();
        // Reaplicar não duplica nem religa.
        $migration->up();

        $rows = EntidadeModulo::query()->where('modulo', EntidadeModule::LegislativeSecretariat->value)->get();

        $this->assertCount(1, $rows);
        $this->assertSame($camara->id, $rows[0]->entidade_id);
        $this->assertTrue($rows[0]->contratado);
        $this->assertFalse($rows[0]->ativo);
        $this->assertNull($rows[0]->ativado_em);
        $this->assertDatabaseMissing('entidade_modulos', ['entidade_id' => $prefeitura->id, 'modulo' => EntidadeModule::LegislativeSecretariat->value]);
        $this->assertDatabaseMissing('entidade_modulos', ['entidade_id' => $independente->id, 'modulo' => EntidadeModule::LegislativeSecretariat->value]);
        $this->assertDatabaseMissing('gabinete_modulos', ['modulo' => GabineteModule::LegislativeSecretariat->value]);
    }

    private function entidade(EntidadeType $tipo): Entidade
    {
        return Entidade::factory()->create([
            'tipo' => $tipo,
            'interface_simplificada' => $tipo === EntidadeType::IndependentOffice,
        ]);
    }

    private function criarGabinete(Entidade $entidade, GabineteType $tipo, string $nome = 'Secretaria legislativa'): Gabinete
    {
        return app(EstruturaProvisioningService::class)->criarGabinete($entidade, $tipo, ['nome' => $nome]);
    }
}

<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\EntityModule;
use App\Enums\EntityType;
use App\Enums\OfficeModule;
use App\Enums\OfficeType;
use App\Models\Entity;
use App\Models\EntityModuleSubscription;
use App\Models\Office;
use App\Models\OfficeModuleSubscription;
use App\Models\User;
use App\Services\Entidades\EstruturaProvisioningService;
use App\Services\Modules\EntityModuleManager;
use App\Services\Modules\OfficeModuleCatalog;
use App\Services\Modules\OfficeModuleManager;
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
        $camara = $this->entidade(EntityType::CityCouncil);

        $secretaria = $this->criarGabinete($camara, OfficeType::LegislativeSecretariat);

        $this->assertSame(OfficeType::LegislativeSecretariat, $secretaria->tipo_gabinete);
        $this->assertSame('Secretaria legislativa', $secretaria->tipo_gabinete->label());
        $this->assertSame('Secretário legislativo', $secretaria->tipo_gabinete->leaderLabel());
        $this->assertDatabaseHas('gabinete_modulos', [
            'gabinete_id' => $secretaria->id,
            'modulo' => OfficeModule::LegislativeSecretariat->value,
            'ativo' => true,
        ]);
    }

    public function test_city_council_accepts_only_one_legislative_secretariat(): void
    {
        $camara = $this->entidade(EntityType::CityCouncil);
        $this->criarGabinete($camara, OfficeType::LegislativeSecretariat);

        try {
            $this->criarGabinete($camara, OfficeType::LegislativeSecretariat, 'Outra secretaria');
            $this->fail('A segunda Secretaria legislativa deveria ser recusada.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('A entidade já tem uma Secretaria legislativa.', $e->getMessage());
        }

        // Gabinetes de vereador continuam livres, e outra Câmara tem a sua.
        $this->criarGabinete($camara, OfficeType::CouncilorOffice, 'Gabinete do vereador');
        $this->criarGabinete($this->entidade(EntityType::CityCouncil), OfficeType::LegislativeSecretariat);

        $this->assertSame(1, Office::withoutGlobalScopes()
            ->where('entidade_id', $camara->id)
            ->where('tipo_gabinete', OfficeType::LegislativeSecretariat)
            ->count());
    }

    public function test_soft_deleted_secretariat_still_blocks_a_new_one(): void
    {
        $camara = $this->entidade(EntityType::CityCouncil);
        $this->criarGabinete($camara, OfficeType::LegislativeSecretariat)->delete();

        $this->expectException(InvalidArgumentException::class);

        $this->criarGabinete($camara, OfficeType::LegislativeSecretariat, 'Outra secretaria');
    }

    public function test_other_entidade_types_reject_the_legislative_secretariat(): void
    {
        foreach ([EntityType::CityHall, EntityType::IndependentOffice] as $tipo) {
            try {
                $this->criarGabinete($this->entidade($tipo), OfficeType::LegislativeSecretariat);
                $this->fail("{$tipo->value} não deveria aceitar Secretaria legislativa.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('A entidade não aceita este tipo de gabinete.', $e->getMessage());
            }
        }

        $this->assertFalse(Office::withoutGlobalScopes()
            ->where('tipo_gabinete', OfficeType::LegislativeSecretariat)
            ->exists());
    }

    public function test_entidade_module_is_contracted_but_off_only_for_city_councils(): void
    {
        $camara = $this->entidade(EntityType::CityCouncil);

        $this->assertDatabaseHas('entidade_modulos', [
            'entidade_id' => $camara->id,
            'modulo' => EntityModule::LegislativeSecretariat->value,
            'contratado' => true,
            'ativo' => false,
        ]);

        foreach ([EntityType::CityHall, EntityType::IndependentOffice] as $tipo) {
            $this->assertDatabaseMissing('entidade_modulos', [
                'entidade_id' => $this->entidade($tipo)->id,
                'modulo' => EntityModule::LegislativeSecretariat->value,
            ]);
        }
    }

    public function test_module_is_effective_only_after_the_city_council_turns_it_on(): void
    {
        $camara = $this->entidade(EntityType::CityCouncil);
        $secretaria = $this->criarGabinete($camara, OfficeType::LegislativeSecretariat);
        $vereador = $this->criarGabinete($camara, OfficeType::CouncilorOffice, 'Gabinete do vereador');

        $this->assertFalse(app(OfficeModuleManager::class)->isActive($secretaria, OfficeModule::LegislativeSecretariat));

        app(EntityModuleManager::class)->syncActivation(
            $camara,
            [...app(EntityModuleManager::class)->activeFor($camara), EntityModule::LegislativeSecretariat->value],
            User::factory()->root()->create(),
        );

        // O cache de módulos vale por requisição; a próxima começa limpa.
        app()->forgetScopedInstances();
        $modules = app(OfficeModuleManager::class);
        $this->assertTrue($modules->isActive($secretaria->fresh(), OfficeModule::LegislativeSecretariat));
        // Mesmo com a Câmara ligada, o vereador não ganha a gestão da Secretaria.
        $this->assertFalse($modules->isActive($vereador->fresh(), OfficeModule::LegislativeSecretariat));
    }

    public function test_only_the_secretariat_gabinete_can_enable_the_module(): void
    {
        $camara = $this->entidade(EntityType::CityCouncil);
        $vereador = $this->criarGabinete($camara, OfficeType::CouncilorOffice, 'Gabinete do vereador');
        $modules = app(OfficeModuleManager::class);

        $this->assertNotContains(OfficeModule::LegislativeSecretariat->value, $modules->allEnabled());
        $this->assertNotContains(OfficeModule::LegislativeSecretariat->value, $modules->allEnabled(OfficeType::CouncilorOffice));
        $this->assertContains(OfficeModule::LegislativeSecretariat->value, $modules->allEnabled(OfficeType::LegislativeSecretariat));
        $this->assertDatabaseMissing('gabinete_modulos', [
            'gabinete_id' => $vereador->id,
            'modulo' => OfficeModule::LegislativeSecretariat->value,
            'ativo' => true,
        ]);

        $this->expectException(ValidationException::class);

        $modules->sync($vereador, [
            ...$modules->allEnabled(),
            OfficeModule::LegislativeSecretariat->value,
        ], null);
    }

    public function test_module_requires_schedule_and_knowledge_base_on_both_levels(): void
    {
        $camara = $this->entidade(EntityType::CityCouncil);
        $root = User::factory()->root()->create();
        $entidadeModules = app(EntityModuleManager::class);

        foreach ([
            [EntityModule::LegislativeSecretariat->value, EntityModule::Schedule->value],
            [EntityModule::LegislativeSecretariat->value, EntityModule::KnowledgeBase->value],
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
            [EntityModule::Schedule->value, EntityModule::KnowledgeBase->value, EntityModule::LegislativeSecretariat->value],
            $entidadeModules->validateActivation($camara, [
                EntityModule::LegislativeSecretariat->value,
                EntityModule::KnowledgeBase->value,
                EntityModule::Schedule->value,
            ]),
        );

        $secretaria = $this->criarGabinete($camara, OfficeType::LegislativeSecretariat);

        try {
            app(OfficeModuleManager::class)->sync($secretaria, [
                OfficeModule::Schedule->value,
                OfficeModule::LegislativeSecretariat->value,
            ], null);
            $this->fail('O gabinete não deveria ativar a Secretaria sem Base de Conhecimento.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Base de Conhecimento', $e->errors()['modules'][0]);
        }
    }

    public function test_secretariat_type_offers_only_its_own_modules(): void
    {
        $catalog = app(OfficeModuleCatalog::class);

        $this->assertSame(self::SECRETARIAT_MODULES, array_column(
            $catalog->availableFor(OfficeType::LegislativeSecretariat),
            'value',
        ));

        // Os demais tipos seguem com o catálogo de sempre, sem a gestão da Secretaria.
        $default = array_values(array_diff(
            $catalog->values(),
            [OfficeModule::LegislativeSecretariat->value],
        ));
        $this->assertSame($default, array_column($catalog->availableFor(null), 'value'));
        foreach (OfficeType::cases() as $tipo) {
            if ($tipo !== OfficeType::LegislativeSecretariat) {
                $this->assertSame($default, array_column($catalog->availableFor($tipo), 'value'), $tipo->value);
            }
        }
    }

    public function test_new_secretariat_starts_with_only_its_modules(): void
    {
        $secretaria = $this->criarGabinete($this->entidade(EntityType::CityCouncil), OfficeType::LegislativeSecretariat);

        $this->assertSame(
            self::SECRETARIAT_MODULES,
            app(OfficeModuleManager::class)->allEnabled(OfficeType::LegislativeSecretariat),
        );
        $this->assertEqualsCanonicalizing(
            self::SECRETARIAT_MODULES,
            OfficeModuleSubscription::query()->where('gabinete_id', $secretaria->id)->where('ativo', true)
                ->pluck('modulo')->map(fn (OfficeModule $module): string => $module->value)->all(),
        );
        // Os indisponíveis nem ganham linha desligada.
        $this->assertSame(
            count(self::SECRETARIAT_MODULES),
            OfficeModuleSubscription::query()->where('gabinete_id', $secretaria->id)->count(),
        );
    }

    public function test_secretariat_rejects_modules_unavailable_for_its_type(): void
    {
        $secretaria = $this->criarGabinete($this->entidade(EntityType::CityCouncil), OfficeType::LegislativeSecretariat);
        $modules = app(OfficeModuleManager::class);

        try {
            $modules->sync($secretaria, [
                ...self::SECRETARIAT_MODULES,
                OfficeModule::Relationship->value,
                OfficeModule::Demands->value,
                OfficeModule::Attendances->value,
                OfficeModule::Events->value,
                OfficeModule::Politics->value,
                OfficeModule::Reports->value,
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
            'modulo' => OfficeModule::Demands->value,
        ]);
    }

    public function test_secretariat_uses_schedule_and_whatsapp_without_relationship(): void
    {
        $secretaria = $this->criarGabinete($this->entidade(EntityType::CityCouncil), OfficeType::LegislativeSecretariat);
        $modules = app(OfficeModuleManager::class);

        $modules->sync($secretaria, [OfficeModule::Schedule->value, OfficeModule::WhatsApp->value], null);

        $this->assertSame(
            [OfficeModule::Schedule->value, OfficeModule::WhatsApp->value],
            $modules->activeFor($secretaria),
        );

        $definitions = app(OfficeModuleCatalog::class)->definitions(OfficeType::LegislativeSecretariat);
        $this->assertSame([], $definitions[OfficeModule::Schedule->value]['dependencies']);
        $this->assertSame([OfficeModule::Schedule->value], $definitions[OfficeModule::WhatsApp->value]['any_of']);

        try {
            $modules->sync($secretaria, [OfficeModule::WhatsApp->value], null);
            $this->fail('O WhatsApp da Secretaria deveria exigir a Agenda.');
        } catch (ValidationException $e) {
            $this->assertSame('WhatsApp exige ao menos um destes módulos: Agenda.', $e->errors()['modules'][0]);
        }
    }

    public function test_other_types_keep_schedule_depending_on_relationship(): void
    {
        $vereador = $this->criarGabinete($this->entidade(EntityType::CityCouncil), OfficeType::CouncilorOffice, 'Gabinete do vereador');

        $this->assertSame(
            [OfficeModule::Relationship->value],
            app(OfficeModuleCatalog::class)->definitions(OfficeType::CouncilorOffice)[OfficeModule::Schedule->value]['dependencies'],
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Agenda exige Relacionamento.');

        app(OfficeModuleManager::class)->sync($vereador, [OfficeModule::Schedule->value], null);
    }

    public function test_sync_turns_off_leftover_rows_of_modules_the_type_no_longer_accepts(): void
    {
        $secretaria = $this->criarGabinete($this->entidade(EntityType::CityCouncil), OfficeType::LegislativeSecretariat);
        // Linha gravada antes da restrição do tipo.
        (new OfficeModuleSubscription)->forceFill([
            'gabinete_id' => $secretaria->id,
            'modulo' => OfficeModule::Demands->value,
            'ativo' => true,
            'ativado_em' => now(),
        ])->save();
        $modules = app(OfficeModuleManager::class);

        $modules->sync($secretaria, self::SECRETARIAT_MODULES, null);

        $this->assertNotContains(OfficeModule::Demands->value, $modules->activeFor($secretaria));
        $this->assertDatabaseHas('gabinete_modulos', [
            'gabinete_id' => $secretaria->id,
            'modulo' => OfficeModule::Demands->value,
            'ativo' => false,
        ]);
        $this->assertDatabaseHas('gabinete_modulo_eventos', [
            'gabinete_id' => $secretaria->id,
            'modulo' => OfficeModule::Demands->value,
            'acao' => 'DESATIVADO',
        ]);
    }

    public function test_backfill_migration_contracts_the_module_off_only_for_existing_city_councils(): void
    {
        $camara = $this->entidade(EntityType::CityCouncil);
        $prefeitura = $this->entidade(EntityType::CityHall);
        $independente = $this->entidade(EntityType::IndependentOffice);
        EntityModuleSubscription::query()->where('modulo', EntityModule::LegislativeSecretariat->value)->delete();

        $migration = require database_path('migrations/2026_10_14_000001_enable_secretaria_legislativa_module.php');
        $migration->up();
        // Reaplicar não duplica nem religa.
        $migration->up();

        $rows = EntityModuleSubscription::query()->where('modulo', EntityModule::LegislativeSecretariat->value)->get();

        $this->assertCount(1, $rows);
        $this->assertSame($camara->id, $rows[0]->entidade_id);
        $this->assertTrue($rows[0]->contratado);
        $this->assertFalse($rows[0]->ativo);
        $this->assertNull($rows[0]->ativado_em);
        $this->assertDatabaseMissing('entidade_modulos', ['entidade_id' => $prefeitura->id, 'modulo' => EntityModule::LegislativeSecretariat->value]);
        $this->assertDatabaseMissing('entidade_modulos', ['entidade_id' => $independente->id, 'modulo' => EntityModule::LegislativeSecretariat->value]);
        $this->assertDatabaseMissing('gabinete_modulos', ['modulo' => OfficeModule::LegislativeSecretariat->value]);
    }

    private function entidade(EntityType $tipo): Entity
    {
        return Entity::factory()->create([
            'tipo' => $tipo,
            'interface_simplificada' => $tipo === EntityType::IndependentOffice,
        ]);
    }

    private function criarGabinete(Entity $entidade, OfficeType $tipo, string $nome = 'Secretaria legislativa'): Office
    {
        return app(EstruturaProvisioningService::class)->criarGabinete($entidade, $tipo, ['nome' => $nome]);
    }
}

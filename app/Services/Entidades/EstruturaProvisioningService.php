<?php

namespace App\Services\Entidades;

use App\Enums\EntityStatus;
use App\Enums\EntityType;
use App\Enums\OfficeStatus;
use App\Enums\OfficeType;
use App\Models\Entity;
use App\Models\Office;
use App\Models\User;
use App\Services\Modules\OfficeModuleManager;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Nascimento de entidade e gabinete no GAB.
 *
 * É o que a antiga criação manual pela administração fazia (licença e módulos
 * da entidade, módulos do gabinete, localização herdada, município eleitoral,
 * slug único) e que agora só o espelhamento da estrutura do Govnex Hub
 * dispara (`HubEstruturaSyncService`). Não cria usuário: o acesso ao que
 * nasce aqui vem dos vínculos do Hub.
 *
 * O slug é sempre derivado do nome e **globalmente** único — é URL do GAB,
 * nunca o slug do Hub, que muda a cada renomeação.
 */
class EstruturaProvisioningService
{
    public function __construct(
        private readonly EntityEntitlementService $entitlements,
        private readonly OfficeModuleManager $modules,
        private readonly TsePoliticalDataSyncService $politicalData,
    ) {}

    /**
     * @param  array<string, mixed>  $atributos  `nome`, `municipio`, `estado`, `timezone` e, opcionalmente,
     *                                           situação e colunas de ponte com o Hub
     */
    public function criarEntidade(EntityType $tipo, array $atributos): Entity
    {
        return DB::transaction(function () use ($tipo, $atributos): Entity {
            $entidade = Entity::query()->create([
                'status' => EntityStatus::Active,
                'interface_simplificada' => $tipo === EntityType::IndependentOffice,
                ...$atributos,
                'tipo' => $tipo,
                'slug' => $this->slugUnico((string) $atributos['nome'], Entity::query(), 'entidade'),
            ]);

            $this->entitlements->provisionLegacyCompatible($entidade);

            return $entidade;
        });
    }

    /**
     * Município, UF e fuso vêm da entidade; o resto (nome, situação, ponte
     * com o Hub, dados do titular quando houver) de `$atributos`. Todos os
     * módulos que o tipo aceita começam ligados — o mesmo padrão da criação
     * manual.
     *
     * @param  array<string, mixed>  $atributos
     * @param  array<string, scalar|null>  $contextoModulos
     *
     * @throws InvalidArgumentException quando a entidade não aceita o tipo, é
     *                                  gabinete independente que já tem gabinete
     *                                  ou já tem Secretaria legislativa
     */
    public function criarGabinete(
        Entity $entidade,
        OfficeType $tipo,
        array $atributos,
        ?User $administrador = null,
        array $contextoModulos = [],
    ): Office {
        return DB::transaction(function () use ($entidade, $tipo, $atributos, $administrador, $contextoModulos): Office {
            // Trava a entidade para as regras "independente tem um gabinete só"
            // e "uma Secretaria legislativa por Câmara" valerem também com
            // duas criações simultâneas.
            $entidade = Entity::query()->whereKey($entidade->id)->lockForUpdate()->firstOrFail();

            if (! $entidade->tipo->accepts($tipo)) {
                throw new InvalidArgumentException('A entidade não aceita este tipo de gabinete.');
            }

            if ($entidade->tipo === EntityType::IndependentOffice
                && $entidade->gabinetes()->withoutGlobalScopes()->exists()) {
                throw new InvalidArgumentException('Gabinetes independentes não aceitam gabinetes adicionais.');
            }

            // Como na regra acima, gabinete excluído (soft delete) também conta:
            // restaurá-lo não pode deixar a Câmara com duas Secretarias.
            if ($tipo === OfficeType::LegislativeSecretariat
                && $entidade->gabinetes()->withoutGlobalScopes()
                    ->where('tipo_gabinete', OfficeType::LegislativeSecretariat)
                    ->exists()) {
                throw new InvalidArgumentException('A entidade já tem uma Secretaria legislativa.');
            }

            $gabinete = new Office;
            $gabinete->forceFill([
                'status' => OfficeStatus::Active,
                'suspended_at' => null,
                ...$atributos,
                'entidade_id' => $entidade->id,
                'tipo_gabinete' => $tipo,
                'municipio' => $entidade->municipio,
                'estado' => $entidade->estado,
                'timezone' => $entidade->timezone,
                'slug' => $this->slugUnico((string) $atributos['nome'], Office::withoutGlobalScopes(), 'gabinete'),
            ])->save();

            // Município/eleitorado/candidatos já cobrem o Brasil inteiro,
            // independente de gabinete cadastrado — só falta ligar este
            // gabinete ao registro correspondente, se ele já existir.
            $municipio = $this->politicalData->resolveMunicipality($gabinete->estado, $gabinete->municipio);
            $gabinete->forceFill(['municipio_eleitoral_id' => $municipio?->id])->save();

            $this->modules->sync($gabinete, $this->modules->allEnabled($tipo), $administrador, $contextoModulos);

            return $gabinete;
        });
    }

    /** @param  Builder<Entity>|Builder<Office>  $query */
    private function slugUnico(string $nome, Builder $query, string $padrao): string
    {
        $base = Str::slug($nome) ?: $padrao;
        $slug = $base;
        $sufixo = 2;

        while ((clone $query)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$sufixo}";
            $sufixo++;
        }

        return $slug;
    }
}

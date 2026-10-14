<?php

namespace App\Services\Entidades;

use App\Enums\EntidadeStatus;
use App\Enums\EntidadeType;
use App\Enums\GabineteStatus;
use App\Enums\GabineteType;
use App\Models\Entidade;
use App\Models\Gabinete;
use App\Models\User;
use App\Services\Modules\GabineteModuleManager;
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
        private readonly EntidadeEntitlementService $entitlements,
        private readonly GabineteModuleManager $modules,
        private readonly TsePoliticalDataSyncService $politicalData,
    ) {}

    /**
     * @param  array<string, mixed>  $atributos  `nome`, `municipio`, `estado`, `timezone` e, opcionalmente,
     *                                           situação e colunas de ponte com o Hub
     */
    public function criarEntidade(EntidadeType $tipo, array $atributos): Entidade
    {
        return DB::transaction(function () use ($tipo, $atributos): Entidade {
            $entidade = Entidade::query()->create([
                'status' => EntidadeStatus::Active,
                'interface_simplificada' => $tipo === EntidadeType::IndependentOffice,
                ...$atributos,
                'tipo' => $tipo,
                'slug' => $this->slugUnico((string) $atributos['nome'], Entidade::query(), 'entidade'),
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
        Entidade $entidade,
        GabineteType $tipo,
        array $atributos,
        ?User $administrador = null,
        array $contextoModulos = [],
    ): Gabinete {
        return DB::transaction(function () use ($entidade, $tipo, $atributos, $administrador, $contextoModulos): Gabinete {
            // Trava a entidade para as regras "independente tem um gabinete só"
            // e "uma Secretaria legislativa por Câmara" valerem também com
            // duas criações simultâneas.
            $entidade = Entidade::query()->whereKey($entidade->id)->lockForUpdate()->firstOrFail();

            if (! $entidade->tipo->accepts($tipo)) {
                throw new InvalidArgumentException('A entidade não aceita este tipo de gabinete.');
            }

            if ($entidade->tipo === EntidadeType::IndependentOffice
                && $entidade->gabinetes()->withoutGlobalScopes()->exists()) {
                throw new InvalidArgumentException('Gabinetes independentes não aceitam gabinetes adicionais.');
            }

            // Como na regra acima, gabinete excluído (soft delete) também conta:
            // restaurá-lo não pode deixar a Câmara com duas Secretarias.
            if ($tipo === GabineteType::LegislativeSecretariat
                && $entidade->gabinetes()->withoutGlobalScopes()
                    ->where('tipo_gabinete', GabineteType::LegislativeSecretariat)
                    ->exists()) {
                throw new InvalidArgumentException('A entidade já tem uma Secretaria legislativa.');
            }

            $gabinete = new Gabinete;
            $gabinete->forceFill([
                'status' => GabineteStatus::Active,
                'suspended_at' => null,
                ...$atributos,
                'entidade_id' => $entidade->id,
                'tipo_gabinete' => $tipo,
                'municipio' => $entidade->municipio,
                'estado' => $entidade->estado,
                'timezone' => $entidade->timezone,
                'slug' => $this->slugUnico((string) $atributos['nome'], Gabinete::withoutGlobalScopes(), 'gabinete'),
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

    /** @param  Builder<Entidade>|Builder<Gabinete>  $query */
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

<?php

namespace App\Services\Hub;

use App\Enums\EntityType;
use App\Enums\OfficeType;

/**
 * Traduz os tipos da estrutura do Govnex Hub para os do GAB.
 *
 * O Hub classifica entidades e unidades num vocabulário amplo (escola,
 * hospital, autarquia…); o GAB só conhece três tipos de entidade e cinco de
 * gabinete. O que não tem equivalente devolve `null` e quem chama ignora o
 * item com log — nunca inventa um tipo.
 *
 * O tipo do gabinete não viaja do Hub: é derivado da combinação tipo da
 * entidade + tipo da unidade, e sempre respeita
 * `EntityType::allowedGabineteTypes()`.
 */
class HubTipoMapper
{
    /**
     * Tipos de unidade do Hub que, numa Câmara ou Prefeitura, viram setor
     * administrativo: estrutura administrativa e áreas-meio. Equipamentos
     * (escola, creche, saúde, CRAS…) e `OUTRA` ficam de fora.
     */
    private const ADMINISTRATIVAS = [
        'SECRETARIA', 'DIRETORIA', 'DEPARTAMENTO', 'COORDENADORIA', 'GERENCIA',
        'SETOR', 'NUCLEO', 'ASSESSORIA', 'PROCURADORIA', 'CONTROLADORIA',
        'OUVIDORIA', 'COMISSAO', 'RECURSOS_HUMANOS', 'TECNOLOGIA_INFORMACAO',
        'FINANCEIRO', 'COMPRAS', 'PATRIMONIO',
    ];

    /** Os valores coincidem nos dois lados; qualquer outro tipo do Hub não tem equivalente. */
    public function entidadeType(mixed $tipoDoHub): ?EntityType
    {
        return is_string($tipoDoHub) ? EntityType::tryFrom(strtoupper(trim($tipoDoHub))) : null;
    }

    public function gabineteType(EntityType $entidade, mixed $tipoDaUnidade): ?OfficeType
    {
        if (! is_string($tipoDaUnidade)) {
            return null;
        }

        $tipo = strtoupper(trim($tipoDaUnidade));

        $gabinete = match ($entidade) {
            EntityType::IndependentOffice => $tipo === 'GABINETE' ? OfficeType::IndependentOffice : null,
            EntityType::CityCouncil => match (true) {
                $tipo === 'GABINETE' => OfficeType::CouncilorOffice,
                in_array($tipo, self::ADMINISTRATIVAS, true) => OfficeType::AdministrativeDepartment,
                default => null,
            },
            EntityType::CityHall => match (true) {
                $tipo === 'GABINETE' => OfficeType::MayorOffice,
                $tipo === 'SECRETARIA' => OfficeType::Secretariat,
                in_array($tipo, self::ADMINISTRATIVAS, true) => OfficeType::AdministrativeDepartment,
                default => null,
            },
        };

        return $gabinete !== null && $entidade->accepts($gabinete) ? $gabinete : null;
    }
}

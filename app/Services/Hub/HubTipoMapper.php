<?php

namespace App\Services\Hub;

use App\Enums\EntidadeType;
use App\Enums\GabineteType;

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
 * `EntidadeType::allowedGabineteTypes()`.
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
    public function entidadeType(mixed $tipoDoHub): ?EntidadeType
    {
        return is_string($tipoDoHub) ? EntidadeType::tryFrom(strtoupper(trim($tipoDoHub))) : null;
    }

    public function gabineteType(EntidadeType $entidade, mixed $tipoDaUnidade): ?GabineteType
    {
        if (! is_string($tipoDaUnidade)) {
            return null;
        }

        $tipo = strtoupper(trim($tipoDaUnidade));

        $gabinete = match ($entidade) {
            EntidadeType::IndependentOffice => $tipo === 'GABINETE' ? GabineteType::IndependentOffice : null,
            EntidadeType::CityCouncil => match (true) {
                $tipo === 'GABINETE' => GabineteType::CouncilorOffice,
                in_array($tipo, self::ADMINISTRATIVAS, true) => GabineteType::AdministrativeDepartment,
                default => null,
            },
            EntidadeType::CityHall => match (true) {
                $tipo === 'GABINETE' => GabineteType::MayorOffice,
                $tipo === 'SECRETARIA' => GabineteType::Secretariat,
                in_array($tipo, self::ADMINISTRATIVAS, true) => GabineteType::AdministrativeDepartment,
                default => null,
            },
        };

        return $gabinete !== null && $entidade->accepts($gabinete) ? $gabinete : null;
    }
}

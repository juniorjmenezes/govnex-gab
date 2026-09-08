<?php

namespace App\Enums;

/**
 * Resultado é um registro opcional do desfecho de uma demanda resolvida.
 * Não é um status — o status já diz que a demanda está Resolvida; o
 * resultado só qualifica como ela foi resolvida, para fins de relatório.
 */
enum DemandResultado: string
{
    case Atendida = 'atendida';
    case ParcialmenteAtendida = 'parcialmente_atendida';
    case NaoAtendida = 'nao_atendida';
    case OrientacaoPrestada = 'orientacao_prestada';
    case EncaminhadaDefinitivamente = 'encaminhada_definitivamente';
    case Duplicada = 'duplicada';
    case Outra = 'outra';

    public function label(): string
    {
        return match ($this) {
            self::Atendida => 'Atendida',
            self::ParcialmenteAtendida => 'Parcialmente atendida',
            self::NaoAtendida => 'Não atendida',
            self::OrientacaoPrestada => 'Orientação prestada',
            self::EncaminhadaDefinitivamente => 'Encaminhada definitivamente',
            self::Duplicada => 'Duplicada',
            self::Outra => 'Outra',
        };
    }
}

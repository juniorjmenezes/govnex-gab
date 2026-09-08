<?php

namespace App\Enums;

enum AppointmentRecurrence: string
{
    case None = 'nenhuma';
    case Daily = 'diaria';
    case Weekly = 'semanal';
    case Monthly = 'mensal';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Não se repete',
            self::Daily => 'Diariamente',
            self::Weekly => 'Semanalmente',
            self::Monthly => 'Mensalmente',
        };
    }
}

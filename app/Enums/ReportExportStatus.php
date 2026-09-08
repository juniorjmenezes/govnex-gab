<?php

namespace App\Enums;

enum ReportExportStatus: string
{
    case Pending = 'pendente';
    case Processing = 'processando';
    case Completed = 'concluido';
    case Failed = 'falhou';
    case Cancelled = 'cancelado';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Processing => 'Processando',
            self::Completed => 'Concluído',
            self::Failed => 'Falhou',
            self::Cancelled => 'Cancelado',
        };
    }
}

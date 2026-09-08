<?php

namespace App\Enums;

enum ReportExportFormat: string
{
    case Pdf = 'pdf';
    case Xlsx = 'xlsx';

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Xlsx => 'XLSX',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}

<?php

namespace App\Jobs;

use App\Enums\GabineteModule;
use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Reports\ReportExportGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly string $exportId) {}

    public function handle(ReportExportGenerator $generator, GabineteModuleManager $modules): void
    {
        $export = ReportExport::withoutGlobalScopes()->findOrFail($this->exportId);
        if (! $modules->isActive($export->gabinete_id, GabineteModule::Reports)) {
            $export->forceFill([
                'status' => ReportExportStatus::Cancelled,
                'erro' => 'O módulo Relatórios foi desativado antes do processamento.',
                'concluido_em' => now(),
            ])->save();

            return;
        }
        $export->forceFill([
            'status' => ReportExportStatus::Processing,
            'iniciado_em' => now(),
            'erro' => null,
        ])->save();

        $file = $generator->generate($export);

        $export->forceFill([
            'status' => ReportExportStatus::Completed,
            'disk' => $file['disk'],
            'caminho' => $file['path'],
            'nome_arquivo' => $file['name'],
            'mime_type' => $file['mime'],
            'tamanho' => $file['size'],
            'concluido_em' => now(),
            'expira_em' => now()->addDays(7),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        ReportExport::withoutGlobalScopes()
            ->whereKey($this->exportId)
            ->update([
                'status' => ReportExportStatus::Failed->value,
                'erro' => mb_substr($exception?->getMessage() ?? 'Falha desconhecida ao gerar o relatório.', 0, 2000),
                'concluido_em' => now(),
            ]);

        Log::error('Falha ao gerar exportação de relatório.', [
            'export_id' => $this->exportId,
            'exception' => $exception,
        ]);
    }
}

<?php

namespace App\Services\Reports;

use App\Enums\ReportExportFormat;
use App\Models\Gabinete;
use App\Models\ReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ImageCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class ReportExportGenerator
{
    public function __construct(private readonly DemandReportService $reports) {}

    /** @return array{disk: string, path: string, name: string, mime: string, size: int} */
    public function generate(ReportExport $export): array
    {
        $office = Gabinete::withoutGlobalScopes()->findOrFail($export->gabinete_id);
        $data = $this->reports->export($export->gabinete_id, $export->filtros);
        $extension = $export->formato->value;
        $name = "relatorio-demandas-{$export->created_at?->format('Ymd-His')}-".substr($export->id, 0, 8).".{$extension}";
        $disk = 'local';
        $path = "reports/{$export->gabinete_id}/{$export->id}.{$extension}";
        $absolutePath = Storage::disk($disk)->path($path);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0755, true);
        }

        if ($export->formato === ReportExportFormat::Pdf) {
            $this->generatePdf($absolutePath, $office, $export->filtros, $data);
        } else {
            $this->generateXlsx($absolutePath, $office, $export->filtros, $data);
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'name' => $name,
            'mime' => $export->formato->mimeType(),
            'size' => Storage::disk($disk)->size($path),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $data
     */
    private function generatePdf(string $path, Gabinete $office, array $filters, array $data): void
    {
        Pdf::loadView('reports.demands', [
            'office' => $office,
            'filters' => $filters,
            'summary' => $data['summary'],
            'charts' => $data['charts'],
            'productivity' => $data['productivity'],
            'waitingReferrals' => $data['waitingReferrals'],
            'demands' => $data['demands'],
            'generatedAt' => now(),
            'primaryColor' => '#'.$this->primaryColor($office),
            'primaryForeground' => '#'.$this->contrastingColor($this->primaryColor($office)),
            'logoDataUri' => $this->logoDataUri($office),
        ])
            ->setPaper('a4', 'landscape')
            ->save($path);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $data
     */
    private function generateXlsx(string $path, Gabinete $office, array $filters, array $data): void
    {
        $defaultStyle = new Style(fontSize: 10, fontName: 'Arial');
        $options = new Options(FALLBACK_STYLE: $defaultStyle);
        $writer = new Writer($options);
        $writer->openToFile($path);

        try {

            $primaryColor = $this->primaryColor($office);

            $primaryForeground = $this->contrastingColor($primaryColor);
            $titleStyle = new Style(
                fontBold: true,
                fontSize: 16,
                fontColor: $primaryForeground,
                backgroundColor: $primaryColor,
            );
            $sectionStyle = new Style(
                fontBold: true,
                fontSize: 11,
                fontColor: $primaryForeground,
                backgroundColor: $primaryColor,
            );
            $headerStyle = new Style(
                fontBold: true,
                fontColor: $primaryForeground,
                backgroundColor: $primaryColor,
                cellAlignment: CellAlignment::CENTER,
                shouldWrapText: true,
            );
            $mutedStyle = new Style(fontColor: '64748B');
            $dateStyle = new Style(format: 'dd/mm/yyyy');

            $summarySheet = $writer->getCurrentSheet();
            $summarySheet->setName('Resumo');
            $summarySheet->setColumnWidth(28, 1);
            $summarySheet->setColumnWidth(18, 2);
            $summarySheet->setColumnWidthForRange(20, 3, 4);
            $logoPath = $this->logoPath($office);
            $titleCells = [
                Cell::fromValue($office->cabecalho_relatorios ?: $office->nome, $titleStyle),
                Cell::fromValue('', $titleStyle),
                Cell::fromValue('', $titleStyle),
                $logoPath
                    ? new ImageCell($logoPath, 72, 28, $titleStyle, fitToCell: true)
                    : Cell::fromValue('', $titleStyle),
            ];
            $writer->addRow(new Row($titleCells, 32));
            $writer->addRow(Row::fromValuesWithStyle([
                'Período',
                $this->dateRange($filters),
                'Gerado em',
                now()->format('d/m/Y H:i'),
            ], $mutedStyle));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValuesWithStyle(['Indicador', 'Valor'], $sectionStyle));
            foreach ($this->summaryRows($data['summary']) as $row) {
                $writer->addRow(Row::fromValues($row));
            }
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValuesWithStyle(['Status', 'Quantidade'], $sectionStyle));
            foreach ($data['charts']['status'] as $item) {
                $writer->addRow(Row::fromValues([$item['label'], $item['total']]));
            }

            $demandsSheet = $writer->addNewSheetAndMakeItCurrent();
            $demandsSheet->setName('Demandas');
            $demandsSheet->setColumnWidth(16, 1);
            $demandsSheet->setColumnWidth(36, 2);
            $demandsSheet->setColumnWidthForRange(20, 3, 10);
            $demandsSheet->setColumnWidth(14, 11, 12, 13);
            $demandHeaders = [
                'Protocolo', 'Título', 'Cidadão', 'Status', 'Prioridade', 'Categoria',
                'Bairro', 'Responsável', 'Origem', 'Atrasada', 'Abertura', 'Prazo', 'Conclusão',
            ];
            $writer->addRow(Row::fromValuesWithStyle($demandHeaders, $headerStyle, 26));
            foreach ($data['demands'] as $demand) {
                $writer->addRow(Row::fromValuesWithStyles([
                    $demand['protocol'],
                    $demand['title'],
                    $demand['citizen'] ?? 'Não informado',
                    $demand['status_label'],
                    $demand['priority_label'],
                    $demand['category'] ?? 'Não informada',
                    $demand['neighborhood'] ?? 'Não informado',
                    $demand['responsible'] ?? 'Não atribuído',
                    $demand['origin_label'],
                    $demand['overdue'] ? 'Sim' : 'Não',
                    $this->dateValue($demand['opened_at']),
                    $this->dateValue($demand['deadline']),
                    $this->dateValue($demand['completed_at']),
                ], [
                    10 => $dateStyle,
                    11 => $dateStyle,
                    12 => $dateStyle,
                ]));
            }
            $demandsSheet->setAutoFilter(new AutoFilter(0, 1, 12, max(1, count($data['demands']) + 1)));
            $demandsSheet->setPrintTitleRows('1:1');

            $productivitySheet = $writer->addNewSheetAndMakeItCurrent();
            $productivitySheet->setName('Produtividade');
            $productivitySheet->setColumnWidth(32, 1);
            $productivitySheet->setColumnWidthForRange(18, 2, 4);
            $writer->addRow(Row::fromValuesWithStyle(
                ['Responsável', 'Demandas atribuídas', 'Resolvidas', 'Tempo médio (horas)'],
                $headerStyle,
                26,
            ));
            foreach ($data['productivity'] as $member) {
                $writer->addRow(Row::fromValues([
                    $member['name'],
                    $member['assigned'],
                    $member['resolved'],
                    $member['average_resolution_hours'],
                ]));
            }

            $referralsSheet = $writer->addNewSheetAndMakeItCurrent();
            $referralsSheet->setName('Encaminhamentos');
            $referralsSheet->setColumnWidth(18, 1);
            $referralsSheet->setColumnWidth(34, 2, 3);
            $referralsSheet->setColumnWidth(22, 4);
            $referralsSheet->setColumnWidth(16, 5, 6);
            $writer->addRow(Row::fromValuesWithStyle(
                ['Protocolo', 'Demanda', 'Destino', 'Prazo', 'Atrasado'],
                $headerStyle,
                26,
            ));
            foreach ($data['waitingReferrals'] as $referral) {
                $writer->addRow(Row::fromValuesWithStyles([
                    $referral['demand']['protocol'] ?? '',
                    $referral['demand']['title'] ?? '',
                    $referral['recipient'],
                    $this->dateValue($referral['deadline']),
                    $referral['overdue'] ? 'Sim' : 'Não',
                ], [3 => $dateStyle]));
            }
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, array<int, int|float|string>>
     */
    private function summaryRows(array $summary): array
    {
        return [
            ['Demandas no período', (int) $summary['total']],
            ['Demandas abertas', (int) $summary['open']],
            ['Demandas resolvidas', (int) $summary['resolved']],
            ['Demandas encerradas', (int) $summary['closed']],
            ['Demandas atrasadas', (int) $summary['overdue']],
            ['Taxa de resolução', number_format((float) $summary['resolution_rate'], 1, ',', '.').' %'],
            ['Tempo médio de resolução', $summary['average_resolution_hours'] === null ? 'Sem dados' : $summary['average_resolution_hours'].' horas'],
            ['Encaminhamentos aguardando retorno', (int) $summary['waiting_referrals']],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function dateRange(array $filters): string
    {
        return CarbonImmutable::parse((string) $filters['inicio'])->format('d/m/Y')
            .' a '.CarbonImmutable::parse((string) $filters['fim'])->format('d/m/Y');
    }

    private function dateValue(?string $value): ?\DateTimeImmutable
    {
        return $value ? new \DateTimeImmutable($value) : null;
    }

    /** @return non-empty-string */
    private function primaryColor(Gabinete $office): string
    {
        $color = ltrim((string) $office->cor_principal, '#');

        return preg_match('/^[0-9A-Fa-f]{6}$/', $color) ? strtoupper($color) : 'C44F00';
    }

    /** @return non-empty-string */
    private function contrastingColor(string $hex): string
    {
        $red = hexdec(substr($hex, 0, 2)) / 255;
        $green = hexdec(substr($hex, 2, 2)) / 255;
        $blue = hexdec(substr($hex, 4, 2)) / 255;
        $linearize = static fn (float $channel): float => $channel <= 0.03928
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
        $luminance = 0.2126 * $linearize($red)
            + 0.7152 * $linearize($green)
            + 0.0722 * $linearize($blue);

        return $luminance > 0.48 ? '111111' : 'FFFFFF';
    }

    // referralStatusLabel() removed: encaminhamentos deixaram de ter status próprio.

    private function logoPath(Gabinete $office): ?string
    {
        if (! $office->logo_path || ! Storage::disk('public')->exists($office->logo_path)) {
            return null;
        }

        return Storage::disk('public')->path($office->logo_path);
    }

    private function logoDataUri(Gabinete $office): ?string
    {
        $path = $this->logoPath($office);

        if (! $path) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}

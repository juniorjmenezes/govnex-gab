<?php

namespace App\Services\Politics\Tse;

use RuntimeException;
use ZipArchive;

/**
 * Valida o conteúdo de um ZIP enviado manualmente antes de criar o job.
 *
 * A assinatura ZIP, sozinha, não prova que o arquivo corresponde ao dataset
 * selecionado. Esta validação lê somente cabeçalhos e uma pequena amostra de
 * linhas, mantendo o custo previsível mesmo nos arquivos grandes do TSE.
 */
class TseUploadedArchiveValidator
{
    private const SAMPLE_ROW_LIMIT = 1000;

    public function __construct(
        private readonly TseDatasetUrlBuilder $urlBuilder,
        private readonly TseDatasetArchiveContract $archiveContract,
    ) {}

    public function assertValid(string $path, string $dataset, int $year, ?string $uf = null): void
    {
        $contract = $this->archiveContract->for($dataset);

        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException("Recebemos um arquivo vazio para {$dataset}/{$year}.");
        }

        $maximumBytes = max(
            1,
            (int) config('services.tse.manual_upload_max_megabytes', 500),
        ) * 1024 * 1024;

        if (filesize($path) > $maximumBytes) {
            throw new RuntimeException(sprintf(
                'O arquivo excede o limite de %d MB para upload pelo navegador.',
                (int) config('services.tse.manual_upload_max_megabytes', 500),
            ));
        }

        $zip = new ZipArchive;
        $status = $zip->open($path, ZipArchive::CHECKCONS);

        if ($status !== true) {
            throw new RuntimeException("O conteúdo de {$dataset}/{$year} não é um arquivo ZIP válido.");
        }

        $matchedHeader = false;
        $requiresYearMatch = isset($contract['year_column']) || ($contract['protocol_year'] ?? false);
        $matchedYear = ! $requiresYearMatch;
        $expectedUf = $this->urlBuilder->requiresUf($dataset) ? mb_strtoupper((string) $uf) : null;
        $matchedUf = $expectedUf === null;
        $foundDataRow = false;
        $matchedEntry = false;
        $totalUncompressedBytes = 0;
        $maximumUncompressedBytes = max(
            1,
            (int) config('services.tse.max_uncompressed_megabytes', 16384),
        ) * 1024 * 1024;
        $maximumCompressionRatio = max(
            1,
            (int) config('services.tse.max_compression_ratio', 200),
        );
        $maximumEntries = max(1, (int) config('services.tse.max_archive_entries', 500));

        try {
            if ($zip->numFiles > $maximumEntries) {
                throw new RuntimeException("O ZIP contém mais de {$maximumEntries} entradas e foi recusado por segurança.");
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stats = $zip->statIndex($index);

                if (is_array($stats)) {
                    // statIndex() sempre traz 'size' e 'comp_size' quando não
                    // retorna false; o max() continua valendo para blindar o
                    // divisor da razão de compressão contra entrada zerada.
                    $uncompressed = max(0, $stats['size']);
                    $compressed = max(1, $stats['comp_size']);
                    $totalUncompressedBytes += $uncompressed;

                    if ($uncompressed > $compressed * $maximumCompressionRatio) {
                        throw new RuntimeException('O ZIP possui uma taxa de compressão incompatível com os arquivos oficiais do TSE.');
                    }
                }

                if ($totalUncompressedBytes > $maximumUncompressedBytes) {
                    throw new RuntimeException('O conteúdo descompactado do ZIP excede o limite de segurança configurado.');
                }
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if (! is_string($name) || ! $this->archiveContract->entryMatches($dataset, $name, $expectedUf)) {
                    continue;
                }

                $matchedEntry = true;

                $stream = $zip->getStream($name);

                if ($stream === false) {
                    continue;
                }

                try {
                    $header = fgetcsv($stream, null, ';', '"', '');

                    if (! is_array($header)) {
                        continue;
                    }

                    $header = array_map(fn ($value): string => $this->normalizeHeader((string) $value), $header);

                    if (! $this->matchesColumns($header, $contract['columns'])) {
                        continue;
                    }

                    $matchedHeader = true;
                    $yearIndex = isset($contract['year_column'])
                        ? array_search($contract['year_column'], $header, true)
                        : false;
                    $protocolIndex = ($contract['protocol_year'] ?? false)
                        ? array_search('NR_PROTOCOLO_REGISTRO', $header, true)
                        : false;
                    $ufIndex = isset($contract['uf_column'])
                        ? array_search($contract['uf_column'], $header, true)
                        : false;

                    $sampled = 0;

                    while ($sampled < self::SAMPLE_ROW_LIMIT && ($values = fgetcsv($stream, null, ';', '"', '')) !== false) {
                        if (count($values) !== count($header)) {
                            continue;
                        }

                        $sampled++;
                        $foundDataRow = true;

                        $rowMatchesYear = ! $requiresYearMatch
                            || ($yearIndex !== false && (int) trim((string) ($values[$yearIndex] ?? '')) === $year)
                            || (
                                $protocolIndex !== false
                                && str_ends_with(trim((string) ($values[$protocolIndex] ?? '')), (string) $year)
                            );
                        $rowMatchesUf = $expectedUf === null || (
                            $ufIndex !== false
                            && mb_strtoupper(trim((string) ($values[$ufIndex] ?? ''))) === $expectedUf
                        );

                        if ($rowMatchesYear && $rowMatchesUf) {
                            $matchedYear = true;
                            $matchedUf = true;

                            return;
                        }
                    }
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            $zip->close();
        }

        if (! $matchedEntry) {
            throw new RuntimeException(
                'O ZIP não contém o arquivo CSV esperado para o dataset selecionado (agregado BRASIL ou arquivo da UF, conforme o conjunto).',
            );
        }

        if (! $matchedHeader) {
            throw new RuntimeException(
                'O ZIP não contém um CSV com as colunas esperadas para o dataset selecionado.',
            );
        }

        if (! $foundDataRow) {
            throw new RuntimeException('O CSV compatível está vazio.');
        }

        if (! $matchedYear) {
            throw new RuntimeException("O conteúdo do ZIP não corresponde ao ano {$year}.");
        }

        if (! $matchedUf) {
            throw new RuntimeException("O conteúdo do ZIP não corresponde à UF {$expectedUf}.");
        }
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $requiredGroups
     */
    private function matchesColumns(array $header, array $requiredGroups): bool
    {
        foreach ($requiredGroups as $alternatives) {
            if (! collect($alternatives)->contains(fn (string $column): bool => in_array($column, $header, true))) {
                return false;
            }
        }

        return true;
    }

    private function normalizeHeader(string $value): string
    {
        $value = ltrim($value, "\xEF\xBB\xBF");

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return mb_strtoupper(trim($value));
    }
}

<?php

namespace App\Filament\Exports\Jobs;

use App\Filament\Exports\BaseExporter;
use Filament\Actions\Exports\Jobs\CreateXlsxFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use League\Csv\Reader as CsvReader;
use League\Csv\Statement;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class CreateNumericXlsxFile extends CreateXlsxFile
{
    public function handle(): void
    {
        $disk = $this->export->getFileDisk();

        $writer = app(Writer::class);
        $writer->openToFile($temporaryFile = tempnam(sys_get_temp_dir(), $this->export->file_name));

        $csvDelimiter = $this->exporter::getCsvDelimiter();
        $moneyIndices = $this->resolveMoneyColumnIndices();
        $moneyStyle = $this->resolveMoneyCellStyle();
        $percentStyle = $this->resolvePercentCellStyle();
        $defaultStyle = $this->exporter->getXlsxCellStyle();

        $writeRowsFromFile = function (string $file, ?Style $style = null) use ($csvDelimiter, $disk, $writer, $moneyIndices, $moneyStyle, $percentStyle, $defaultStyle): void {
            $csvReader = CsvReader::createFromStream($disk->readStream($file));
            $csvReader->setDelimiter($csvDelimiter);
            $csvResults = (new Statement)->process($csvReader);

            foreach ($csvResults->getRecords() as $row) {
                [$normalized, $percentIndices] = $this->normalizeRow(array_values($row), $moneyIndices);
                $columnStyles = [];

                foreach ($moneyIndices as $index) {
                    if (isset($percentIndices[$index])) {
                        continue;
                    }

                    if (isset($normalized[$index]) && is_float($normalized[$index])) {
                        $columnStyles[$index] = $moneyStyle;
                    }
                }

                foreach (array_keys($percentIndices) as $index) {
                    $columnStyles[$index] = $percentStyle;
                }

                $writer->addRow(Row::fromValuesWithStyles($normalized, $style ?? $defaultStyle, $columnStyles));
            }
        };

        $cellStyle = $defaultStyle;

        $writeRowsFromFile(
            $this->export->getFileDirectory() . DIRECTORY_SEPARATOR . 'headers.csv',
            $this->exporter->getXlsxHeaderCellStyle() ?? $cellStyle,
        );

        foreach ($disk->files($this->export->getFileDirectory()) as $file) {
            if (str($file)->endsWith('headers.csv')) {
                continue;
            }

            if (! str($file)->endsWith('.csv')) {
                continue;
            }

            $writeRowsFromFile($file, $cellStyle);
        }

        $writer->close();

        $disk->putFileAs(
            $this->export->getFileDirectory(),
            new File($temporaryFile),
            "{$this->export->file_name}.xlsx",
            Filesystem::VISIBILITY_PRIVATE,
        );

        unlink($temporaryFile);
    }

    /**
     * @param  list<mixed>  $row
     * @param  list<int>  $moneyIndices
     * @return array{0: list<mixed>, 1: array<int, true>}
     */
    protected function normalizeRow(array $row, array $moneyIndices): array
    {
        $percentIndices = [];

        foreach ($row as $index => $value) {
            $percent = $this->tryParsePercentCell($value);

            if ($percent === null) {
                continue;
            }

            $row[$index] = $percent;
            $percentIndices[$index] = true;
        }

        foreach ($moneyIndices as $index) {
            if (! array_key_exists($index, $row) || isset($percentIndices[$index])) {
                continue;
            }

            $value = $row[$index];

            if ($value === null || $value === '') {
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $row[$index] = (float) $value;

                continue;
            }

            if (is_string($value) && is_numeric($value)) {
                $row[$index] = (float) $value;
            }
        }

        return [$row, $percentIndices];
    }

    protected function tryParsePercentCell(mixed $value): ?float
    {
        if (! is_string($value)) {
            return null;
        }

        $raw = trim($value);

        if ($raw === '' || ! str_ends_with($raw, '%')) {
            return null;
        }

        $number = str_replace([' ', ','], ['', '.'], substr($raw, 0, -1));

        if ($number === '' || ! is_numeric($number)) {
            return null;
        }

        return ((float) $number) / 100;
    }

    /**
     * @return list<int>
     */
    protected function resolveMoneyColumnIndices(): array
    {
        $exporterClass = $this->export->exporter;

        if (! is_string($exporterClass) || ! is_subclass_of($exporterClass, BaseExporter::class)) {
            return [];
        }

        $moneyNames = $exporterClass::getMoneyColumnNames();
        $indices = [];

        foreach (array_keys($this->columnMap) as $index => $name) {
            if (in_array($name, $moneyNames, true)) {
                $indices[] = $index;
            }
        }

        return $indices;
    }

    protected function resolveMoneyCellStyle(): Style
    {
        $exporterClass = $this->export->exporter;

        if (is_string($exporterClass) && is_subclass_of($exporterClass, BaseExporter::class)) {
            return $exporterClass::getXlsxMoneyCellStyle();
        }

        return (new Style())
            ->setFormat('#,##0.00')
            ->setShouldWrapText(false);
    }

    protected function resolvePercentCellStyle(): Style
    {
        $exporterClass = $this->export->exporter;

        if (is_string($exporterClass) && is_subclass_of($exporterClass, BaseExporter::class)) {
            return $exporterClass::getXlsxPercentCellStyle();
        }

        return (new Style())
            ->setFormat('0%')
            ->setShouldWrapText(false);
    }
}

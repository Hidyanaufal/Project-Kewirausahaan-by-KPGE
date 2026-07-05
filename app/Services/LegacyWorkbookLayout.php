<?php

namespace App\Services;

class LegacyWorkbookLayout
{
    public const INVOICE_ALIGNED = 'aligned';

    public const INVOICE_SHIFTED = 'shifted';

    public const SUPPLEMENT_KPG = 'kpg';

    public const SUPPLEMENT_KPP = 'kpp';

    /**
     * @param  array<string, int>  $invoiceColumns
     * @param  array<string, int>  $supplementColumns
     */
    public function __construct(
        public readonly string $invoiceLayout,
        public readonly string $supplementLayout,
        public readonly array $invoiceColumns,
        public readonly array $supplementColumns,
    ) {}

    public static function detect(
        array $invoiceHeader,
        array $invoiceSampleRows,
        array $supplementHeader = [],
    ): self {
        $invoiceLayout = self::detectInvoiceLayout($invoiceHeader, $invoiceSampleRows);
        $supplementLayout = self::detectSupplementLayout($supplementHeader);
        $invoiceColumns = self::invoiceColumnMap($invoiceLayout);
        $supplementColumns = self::supplementColumnMap($supplementLayout, $supplementHeader);

        return new self($invoiceLayout, $supplementLayout, $invoiceColumns, $supplementColumns);
    }

    public function invoiceCol(string $key): int
    {
        return $this->invoiceColumns[$key];
    }

    public function supplementCol(string $key): int
    {
        return $this->supplementColumns[$key];
    }

    public function valueAt(array $values, string $sheet, string $key): mixed
    {
        $index = $sheet === 'invoice'
            ? $this->invoiceCol($key)
            : $this->supplementCol($key);

        return $values[$index] ?? null;
    }

    protected static function detectInvoiceLayout(array $header, array $sampleRows): string
    {
        foreach ($sampleRows as $row) {
            $values = $row['values'] ?? $row;

            if (! is_array($values) || self::isEmptyValues($values)) {
                continue;
            }

            if (self::looksLikeDocumentNumber($values[0] ?? null)) {
                return self::INVOICE_ALIGNED;
            }

            if (self::looksLikeDocumentNumber($values[2] ?? null)) {
                return self::INVOICE_SHIFTED;
            }
        }

        return self::INVOICE_SHIFTED;
    }

    protected static function detectSupplementLayout(array $header): string
    {
        if ($header === []) {
            return self::SUPPLEMENT_KPG;
        }

        if (self::findHeaderIndex($header, ['nomor pekerjaan']) !== null) {
            return self::SUPPLEMENT_KPP;
        }

        if (self::findHeaderIndex($header, ['no eo']) !== null) {
            return self::SUPPLEMENT_KPG;
        }

        if (self::findHeaderIndex($header, ['no invoice']) === 3) {
            return self::SUPPLEMENT_KPP;
        }

        return self::SUPPLEMENT_KPG;
    }

    /**
     * @return array<string, int>
     */
    protected static function invoiceColumnMap(string $layout): array
    {
        if ($layout === self::INVOICE_ALIGNED) {
            return [
                'invoice_number' => 0,
                'date' => 1,
                'project_reference' => 2,
                'tax_number' => 3,
                'item_description' => 4,
                'dpp' => 5,
                'profit' => 6,
                'tax_total' => 7,
                'total' => 8,
                'status' => 9,
                'paid_value' => 10,
            ];
        }

        return [
            'invoice_number' => 2,
            'date' => 1,
            'project_reference' => 3,
            'tax_number' => 4,
            'item_description' => 4,
            'dpp' => 5,
            'profit' => 6,
            'tax_total' => 7,
            'total' => 8,
            'status' => 9,
            'paid_value' => 10,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected static function supplementColumnMap(string $layout, array $header): array
    {
        if ($layout === self::SUPPLEMENT_KPP) {
            return [
                'invoice_number' => self::findHeaderIndex($header, ['no invoice']) ?? 3,
                'invoice_number_alt' => self::findHeaderIndex($header, ['no invoice'], 1) ?? 19,
                'date' => self::findHeaderIndex($header, ['tanggal']) ?? 4,
                'contract' => self::findHeaderIndex($header, ['kontrak', 'pengadaan', 'spk']) ?? 5,
                'pekerjaan' => self::findHeaderIndex($header, ['nomor pekerjaan', 'pekerjaan']) ?? 6,
                'project_reference' => self::findHeaderIndex($header, ['kontrak', 'pengadaan', 'spk']) ?? 5,
                'item_description' => self::findHeaderIndex($header, ['judul invoice']) ?? 9,
                'nominal_invoice' => self::findHeaderIndex($header, ['nominal invoice']) ?? 10,
                'profit' => self::findHeaderIndex($header, ['profit']) ?? 11,
                'pre_tax_total' => self::findHeaderIndex($header, ['total stlh profit']) ?? 12,
                'tax_total' => self::findHeaderIndex($header, ['ppn']) ?? 13,
                'total' => self::findHeaderIndex($header, ['total stlh ppn']) ?? 14,
                'due_date' => self::findHeaderIndex($header, ['jatuh tempo']) ?? 15,
                'tax_number' => self::findHeaderIndex($header, ['no faktur', 'faktur']) ?? 17,
                'status' => self::findHeaderIndex($header, ['status bayar']) ?? 18,
                'paid_value' => self::findHeaderIndex($header, ['nilai invoice']) ?? 21,
            ];
        }

        return [
            'invoice_number' => self::findHeaderIndex($header, ['no invoice']) ?? 4,
            'invoice_number_alt' => self::findHeaderIndex($header, ['no invoice'], 1) ?? 20,
            'date' => self::findHeaderIndex($header, ['tanggal']) ?? 5,
            'contract' => self::findHeaderIndex($header, ['kontrak', 'spk']) ?? 6,
            'pekerjaan' => self::findHeaderIndex($header, ['nomor pekerjaan']) ?? 7,
            'project_reference' => self::findHeaderIndex($header, ['no eo']) ?? 8,
            'item_description' => self::findHeaderIndex($header, ['judul invoice']) ?? 9,
            'nominal_invoice' => self::findHeaderIndex($header, ['nominal invoice']) ?? 10,
            'profit' => self::findHeaderIndex($header, ['profit']) ?? 11,
            'pre_tax_total' => self::findHeaderIndex($header, ['total stlh profit']) ?? 12,
            'tax_total' => self::findHeaderIndex($header, ['ppn']) ?? 13,
            'total' => self::findHeaderIndex($header, ['total stlh ppn']) ?? 14,
            'due_date' => self::findHeaderIndex($header, ['jatuh tempo']) ?? 15,
            'tax_number' => self::findHeaderIndex($header, ['no faktur', 'faktur']) ?? 18,
            'status' => self::findHeaderIndex($header, ['status bayar']) ?? 19,
            'paid_value' => self::findHeaderIndex($header, ['nilai invoice']) ?? 21,
        ];
    }

    protected static function findHeaderIndex(array $header, array $needles, int $occurrence = 0): ?int
    {
        $found = 0;

        foreach ($header as $index => $cell) {
            $normalized = strtolower(preg_replace('/\s+/u', ' ', trim((string) $cell)) ?? '');

            foreach ($needles as $needle) {
                if ($normalized !== '' && str_contains($normalized, strtolower($needle))) {
                    if ($found === $occurrence) {
                        return $index;
                    }

                    $found++;

                    break;
                }
            }
        }

        return null;
    }

    protected static function looksLikeDocumentNumber(mixed $value): bool
    {
        $value = strtoupper(trim((string) ($value ?? '')));

        if ($value === '' || $value === '#N/A') {
            return false;
        }

        return (bool) preg_match('/^[A-Z]{2,}\d{2}-\d+$/', str_replace(' ', '', $value));
    }

    protected static function looksLikeSequenceNumber(mixed $value): bool
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' && preg_match('/^\d+(?:\.0+)?$/', $value);
    }

    protected static function isEmptyValues(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) ($value ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}

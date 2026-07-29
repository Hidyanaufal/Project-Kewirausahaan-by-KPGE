<?php

use App\Services\LegacyInvoiceSheetMismatchService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

it('detects invoice sheet rows that do not match supplement totals', function () {
    $path = sys_get_temp_dir() . '/legacy-mismatch-audit-' . uniqid() . '.xlsx';
    createShiftedInvoiceWorkbook($path);

    $service = new LegacyInvoiceSheetMismatchService;
    $result = $service->audit($path);

    expect($result['summary']['sheet_mismatches'])->toBe(2)
        ->and($result['summary']['shifted_row_suspects'])->toBeGreaterThanOrEqual(1)
        ->and(collect($result['mismatches'])->pluck('invoice_number')->all())->toContain('PG25-160', 'PG25-161');

    $pg160 = collect($result['mismatches'])->firstWhere('invoice_number', 'PG25-160');
    expect($pg160['sheet']['total'])->toBe('33,700,182')
        ->and($pg160['supplement']['total'])->toBe('2,194,248')
        ->and($pg160['shifted_from'])->toBe('PG25-161');

    @unlink($path);
});

it('writes supplement amounts back to the invoice sheet', function () {
    $path = sys_get_temp_dir() . '/legacy-mismatch-fix-' . uniqid() . '.xlsx';
    $output = sys_get_temp_dir() . '/legacy-mismatch-fixed-' . uniqid() . '.xlsx';
    createShiftedInvoiceWorkbook($path);

    $service = new LegacyInvoiceSheetMismatchService;
    $result = $service->fixExcel($path, $output);

    expect($result['fixed'])->toBe(2)
        ->and(file_exists($output))->toBeTrue();

    $fixedAudit = $service->audit($output);
    expect($fixedAudit['summary']['sheet_mismatches'])->toBe(0);

    @unlink($path);
    @unlink($output);
});

function createShiftedInvoiceWorkbook(string $path): void
{
    $spreadsheet = new Spreadsheet;

    $invoice = $spreadsheet->getActiveSheet();
    $invoice->setTitle('Invoice');
    $invoice->fromArray([
        ['Nomor Invoice', '', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['', '2/7/2025', 'PG25-159', 'EO25-142', 'Invoice 159', '', '', '217,448', '2,194,248', 'PAID', '2,154,712'],
        ['', '2/7/2025', 'PG25-160', 'EO25-143', 'PENERBITAN SERTIFIKAT PENDAFTARAN MEREK FLOW2MAX', '', '212', '3,339,658', '33,700,182', 'PAID', '29,753,314'],
        ['', '2/7/2025', 'PG25-161', 'EO25-144', 'Invoice 161', '', '3,252,913', '4,375,820', '44,156,000', 'PAID', '38,984,576'],
    ]);

    $supplement = new Worksheet($spreadsheet, 't');
    $spreadsheet->addSheet($supplement);
    $supplement->fromArray([
        ['Bulan', 'Huruf', '', 'No Urut', 'No Invoice', 'Tanggal', 'No.Kontrak / SPK', 'PT', 'NO EO ', 'Judul Invoice', 'Nominal Invoice', 'Profit', 'Total stlh Profit', 'PPN', 'Total stlh PPN', 'Jatuh Tempo', 'POSISI Terakhir', 'PIC KPG', 'NO FAKTUR', 'Status Bayar', 'No Invoice', 'Nilai Invoice yang dibayar'],
        ['Juli', 'PG25-', '', '160', 'PG25-160', '2025-07-02', '', 'PGE', 'EO25-143', 'PENERBITAN SERTIFIKAT PENDAFTARAN MEREK FLOW2MAX', '1,765,000', '211,800', '1,976,800', '217,448', '2,194,248', '', '', 'ALIF', '', 'PAID', 'PG25-160', '2,154,712'],
        ['Juli', 'PG25-', '', '161', 'PG25-161', '2025-07-02', '', 'PGE', 'EO25-144', 'Invoice 161', '27,107,611', '3,252,913', '30,360,524', '3,339,658', '33,700,182', '', '', 'ALIF', '', 'PAID', 'PG25-161', '29,753,314'],
    ]);

    (new Xlsx($spreadsheet))->save($path);
}

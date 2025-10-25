<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Enums\Accounting\DocumentEntityType;
use App\Helpers\AuthHelper;
use App\Models\Company;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountsReceivableAging extends BaseAgingReportPage implements EnhancedReport
{
    protected function getEntityType(): DocumentEntityType
    {
        return DocumentEntityType::Client;
    }

    public function buildReportEnhanced(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $report = $this->report;

        $start = Carbon::parse($report->getStartDate());
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $startFormatted = $start->format('d M Y');
        $daysPerPeriod = (int) ($this->getFilterState('days_per_period') ?? 30);
        $numberOfPeriods = (int) ($this->getFilterState('number_of_periods') ?? 4);

        $overallTotal = $report->getOverallTotals();
        $columns = array_keys($overallTotal);

        // Mapping kolom dinamis
        $columnMap = [
            'entity_name'  => 'Customer Name',
            'current'      => 'Current',
            'total'        => 'Total',
        ];

        for ($i = 1; $i <= $numberOfPeriods; $i++) {
            $startDay = ($i - 1) * $daysPerPeriod + 1;
            $endDay = $i * $daysPerPeriod;
            $columnMap["period_{$i}"] = "{$startDay} - {$endDay} Days";
        }

        $columnMap['over_periods'] = "> " . ($numberOfPeriods * $daysPerPeriod) . " Days";

        // Urutkan mapped columns sesuai urutan aslinya
        $mappedColumns = array_map(fn($col) => $columnMap[$col] ?? ucfirst(str_replace('_', ' ', $col)), $columns);

        $toNumber = fn($v) => is_null($v)
            ? 0
            : (float) str_replace(',', '.', preg_replace('/[^\d,]/', '', $v));

        $data = [
            'filename'           => "{$company->name} {$report->getTitle()} {$now}.pdf",
            'company_name'       => $company->name,
            'report_title'       => $report->getTitle(),
            'report_period'      => "As of {$startFormatted}",
            'columns'            => $mappedColumns,
            'rows'               => [],
        ];

        $rows = collect();

        // === Mulai mapping data ===
        foreach ($report->getCategories() as $cat) {
            foreach ($cat->data as $item) {
                // Susun urutan data sesuai kolom
                $rowData = [];

                foreach ($columns as $colKey) {
                    if ($colKey === 'entity_name') {
                        $rowData[] = is_array($item[$colKey])
                            ? ($item[$colKey]['name'] ?? '')
                            : ($item[$colKey] ?? '');
                    } else {
                        $rowData[] = $toNumber($item[$colKey] ?? 0);
                    }
                }

                $rows->push([
                    'data' => $rowData,
                    'style' => [
                        'font-size' => '10px',
                        'padding-left' => '20px',
                    ],
                ]);
            }
        }

        // === Tambahkan baris total akhir (optional) ===
        $totalRow = [];
        foreach ($columns as $colKey) {
            $totalRow[] = $colKey === 'entity_name'
                ? 'Total'
                : $toNumber($overallTotal[$colKey] ?? 0);
        }

        $rows->push([
            'data' => $totalRow,
            'style' => [
                'font-weight' => 'bold',
                'font-size' => '11px',
            ],
        ]);

        $data['rows'] = $rows->toArray();

        return $data;
    }


    public function exportPDF(): StreamedResponse
    {
        return $this->exportService->exportToPdfEnhanced(
            data: $this->buildReportEnhanced()
        );
    }
}

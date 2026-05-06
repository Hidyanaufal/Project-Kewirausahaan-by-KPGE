<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Enums\Accounting\DocumentEntityType;
use App\Helpers\AuthHelper;
use App\Models\Company;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\MaxWidth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountsPayableAging extends BaseAgingReportPage implements EnhancedReport
{
    protected function getEntityType(): DocumentEntityType
    {
        return DocumentEntityType::Vendor;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export')
                ->button()
                ->outlined()
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After)
                ->slideOver()
                ->modalWidth(MaxWidth::Small)
                ->form([
                    Forms\Components\Select::make('format')
                        ->options([
                            'pdf' => 'PDF',
                            'xlsx' => 'XLSX',
                        ])
                        ->default('pdf')
                        ->required(),
                    Forms\Components\Select::make('template')
                        ->options([
                            'default' => 'Aging Payable Summary',
                            'detail' => 'Aging Payable Detail',
                            // 'outstanding' => 'Outstanding Bill',
                        ])
                        ->required(),
                ])
                ->action(function (array $data) {
                    $format = $data['format'];
                    $template = $data['template'];

                    if ($format === 'xlsx') {
                        return $this->exportXLSX($template);
                    }

                    return $this->exportPDF($template);
                }),
        ];
    }

    public function buildReportEnhanced(?string $template = null, string $format = 'pdf'): array
    {
        return match ($template) {
            'detail' => $this->__buildReportDetail(),
            // 'outstanding' => $this->__buildReportOutstanding(),
            default => $this->__buildReportDefault(),
        };
    }

    protected function __buildReportOutstanding(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $asOfDate = $this->report->getStartDate();
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        // 🔹 Ambil dari report service khusus payable
        $data = $this->reportService->buildOutstandingReportBillLevel(
            $this->getFormattedAsOfDate(),
            $this->company->id
        );

        $columns = array_filter($data['columns'], fn($col) => strtolower($col) !== 'bill');
        $columns = array_values($columns);

        $rows = collect();
        $grouped = collect($data['rows'])->groupBy('vendor_name');

        foreach ($grouped as $vendorName => $bills) {
            $headerRow = array_fill(0, count($columns), null);
            $headerRow[0] = $vendorName;

            $rows->push([
                'data' => $headerRow,
                'style' => ['font-weight' => 'bold'],
            ]);

            $subtotal = [
                'bill_amount' => 0,
                'base_owing' => 0,
                'tax_owing' => 0,
            ];

            foreach ($bills as $bill) {
                $rowData = [
                    $bill['bill_number'],
                    $bill['bill_date'],
                    $bill['due_date'],
                    number_format($bill['bill_amount'] / 100, 2, ',', '.'),
                    number_format($bill['base_owing'] / 100, 2, ',', '.'),
                    number_format($bill['tax_owing'] / 100, 2, ',', '.'),
                    $bill['age_from_due'],
                ];

                $subtotal['bill_amount'] += $bill['bill_amount'];
                $subtotal['base_owing'] += $bill['base_owing'];
                $subtotal['tax_owing'] += $bill['tax_owing'];

                $rows->push(['data' => $rowData]);
            }

            $subtotalRow = [
                '',
                '',
                '',
                number_format($subtotal['bill_amount'] / 100, 2, ',', '.'),
                number_format($subtotal['base_owing'] / 100, 2, ',', '.'),
                number_format($subtotal['tax_owing'] / 100, 2, ',', '.'),
                '',
            ];

            $rows->push([
                'data' => $subtotalRow,
                'style' => ['font-weight' => 'bold', 'color' => 'rgb(150, 50, 50)'],
            ]);
        }

        return [
            'filename' => "{$company->name} Outstanding Bill {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'Outstanding Bill',
            'report_period' => "As of " . Carbon::parse($asOfDate)->format('d M Y'),
            'columns' => $columns,
            'rows' => $rows->toArray(),
        ];
    }

    protected function __buildReportDetail(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $asOfDate = $this->report->getStartDate();
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $daysPerPeriod = (int)($this->getFilterState('days_per_period') ?? 30);
        $numberOfPeriods = (int)($this->getFilterState('number_of_periods') ?? 4);

        $dataDetail = $this->reportService->buildAgingReportBillLevel(
            $this->getFormattedAsOfDate(),
            $this->getEntityType(),
            $daysPerPeriod,
            $numberOfPeriods,
            $this->company->id
        );

        $columns = $dataDetail['columns'];
        $rows = collect();
        $grouped = collect($dataDetail['rows'])->groupBy('vendor_name');

        foreach ($grouped as $vendorName => $bills) {
            $vendorHeader = array_fill(0, count($columns), null);
            $vendorHeader[0] = $vendorName;

            $rows->push(['data' => $vendorHeader, 'style' => ['font-weight' => 'bold']]);

            $subtotal = ['total_base' => 0];
            $agingKeys = array_keys($bills->first()['aging']);

            foreach ($agingKeys as $key) {
                $subtotal[$key] = 0;
            }

            foreach ($bills as $r) {
                $rowData = [
                    $r['bill_number'],
                    $r['bill_date'],
                    number_format($r['total_base'] / 100, 2, ',', '.'),
                ];

                foreach ($r['aging'] as $value) {
                    $rowData[] = number_format($value / 100, 2, ',', '.');
                }

                $subtotal['total_base'] += $r['total_base'];
                foreach ($r['aging'] as $key => $value) {
                    $subtotal[$key] += $value;
                }

                $rows->push(['data' => $rowData]);
            }

            $subtotalRow = ['', '', number_format($subtotal['total_base'] / 100, 2, ',', '.')];
            foreach ($agingKeys as $key) {
                $subtotalRow[] = number_format($subtotal[$key] / 100, 2, ',', '.');
            }

            $rows->push([
                'data' => $subtotalRow,
                'style' => ['font-weight' => 'bold', 'color' => 'rgb(150, 50, 50)'],
            ]);
        }

        return [
            'filename' => "{$company->name} Aging Payable Detail {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'Aging Payable Detail',
            'report_period' => "As of " . Carbon::parse($asOfDate)->format('d M Y'),
            'columns' => $columns,
            'rows' => $rows->toArray(),
        ];
    }

    protected function __buildReportDefault(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $report = $this->report;
        $start = Carbon::parse($report->getStartDate());
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $startFormatted = $start->format('d M Y');
        $daysPerPeriod = (int)($this->getFilterState('days_per_period') ?? 30);
        $numberOfPeriods = (int)($this->getFilterState('number_of_periods') ?? 4);

        $overallTotal = $report->getOverallTotals();
        $columns = array_keys($overallTotal);

        $columnMap = [
            'entity_name' => 'Vendor Name',
            'current' => 'Current',
            'total' => 'Total',
        ];

        for ($i = 1; $i <= $numberOfPeriods; $i++) {
            $startDay = ($i - 1) * $daysPerPeriod + 1;
            $endDay = $i * $daysPerPeriod;
            $columnMap["period_{$i}"] = "{$startDay} - {$endDay} Days";
        }

        $columnMap['over_periods'] = "> " . ($numberOfPeriods * $daysPerPeriod) . " Days";

        $mappedColumns = array_map(fn($col) => $columnMap[$col] ?? ucfirst(str_replace('_', ' ', $col)), $columns);

        $rows = collect();

        foreach ($report->getCategories() as $cat) {
            foreach ($cat->data as $item) {
                $rowData = [];

                foreach ($columns as $colKey) {
                    $rowData[] = is_array($item[$colKey])
                        ? ($item[$colKey]['name'] ?? '')
                        : ($item[$colKey] ?? 0);
                }

                $rows->push([
                    'data' => $rowData,
                    'style' => ['padding-left' => '15px'],
                ]);
            }
        }
        $totalRowData = [];

        foreach ($columns as $colKey) {
            if ($colKey === 'entity_name') {
                $totalRowData[] = 'Total';
            } else {
                $totalRowData[] = $overallTotal[$colKey] ?? 0;
            }
        }

        $rows->push([
            'data' => $totalRowData,
            'style' => [
                'font-weight' => 'bold',
                'color' => 'rgb(150, 50, 50)',
            ],
        ]);


        return [
            'filename' => "{$company->name} Aging Payable Summary {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'Aging Payable Summary',
            'report_period' => "As of {$startFormatted}",
            'columns' => $mappedColumns,
            'rows' => $rows->toArray(),
        ];
    }

    public function exportPDF($template = null): StreamedResponse
    {
        return $this->exportService->exportToPdfEnhanced(
            data: $this->buildReportEnhanced(template: $template, format: 'pdf')
        );
    }

    public function exportXLSX($template = null): BinaryFileResponse
    {
        return $this->exportService->exportToExcelEnhanced(
            data: $this->buildReportEnhanced(template: $template, format: 'xlsx')
        );
    }
}

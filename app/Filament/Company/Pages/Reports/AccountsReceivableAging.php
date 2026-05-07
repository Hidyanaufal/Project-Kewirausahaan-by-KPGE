<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Enums\Accounting\DocumentEntityType;
use App\Helpers\AuthHelper;
use App\Models\Company;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Actions\Action;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\MaxWidth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Str;

use function PHPSTORM_META\type;

class AccountsReceivableAging extends BaseAgingReportPage implements EnhancedReport
{
    protected function getEntityType(): DocumentEntityType
    {
        return DocumentEntityType::Client;
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
                            'default' => 'Aging Receivable Summary',
                            'detail' => 'Aging Receivable Detail',
                            'outstanding' => 'Outstanding Invoice',
                        ])
                        ->required(),
                ])
                ->action(function (array $data) {
                    $format     = $data['format'];
                    $template   = $data['template'];

                    if ($format == 'xlsx') {
                        return $this->exportXLSX($template);
                    }

                    if ($format == 'pdf') {
                        return $this->exportPDF($template);
                    }
                })
        ];
    }

    public function buildReportEnhanced(
        ?string $template = null,
        string $format = 'pdf',
    ): array {
        if ($template == 'detail') {
            return $this->__buildReportDetail();
        }
        if ($template == 'outstanding') {
            return $this->__buildReportOutstanding($format);
        }
        return $this->__buildReportDefault();
    }

    public function __buildReportOutstanding(string $format = 'pdf'): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $asOfDate = $this->report->getStartDate();
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $data = $this->reportService->buildOutstandingReportInvoiceLevel(
            $this->getFormattedAsOfDate(),
            $this->getEntityType(),
            $this->company->id
        );

        $columns = array_filter($data['columns'], fn($col) => strtolower($col) !== 'invoice');
        $columns = array_values($columns);

        $rows = collect();
        $grouped = collect($data['rows'])->groupBy('customer_name');
        $grand = [
            'invoice_amount' => 0,
            'prime_owing' => 0,
            'tax_owing' => 0,
        ];

        foreach ($grouped as $customerName => $invoices) {
            $headerRow = array_fill(0, count($columns), null);
            $headerRow[0] = $customerName;

            $rows->push([
                'data' => $headerRow,
                'style' => [
                    'font-weight' => 'bold',
                ],
            ]);

            $subtotal = [
                'invoice_amount' => 0,
                'prime_owing' => 0,
                'tax_owing' => 0,
            ];

            foreach ($invoices as $inv) {
                $project = $inv['project_name'] ?? '';
                if ($format === 'pdf') {
                    $project = $project ? preg_replace("/\s+/", ' ', trim((string) $project)) : '';
                    // Keep PDF compact: avoid wrapping by truncating more aggressively.
                    $project = $project ? Str::words($project, 4, '…') : '';
                }
                $rowData = [
                    $inv['invoice_number'],
                    $project,
                    $inv['invoice_date'],
                    $inv['due_date'],
                    number_format($inv['invoice_amount'] / 100, 2, ',', '.'),
                    number_format($inv['prime_owing'] / 100, 2, ',', '.'),
                    number_format($inv['tax_owing'] / 100, 2, ',', '.'),
                    $inv['age_from_due'],
                ];

                $subtotal['invoice_amount'] += $inv['invoice_amount'];
                $subtotal['prime_owing'] += $inv['prime_owing'];
                $subtotal['tax_owing'] += $inv['tax_owing'];
                $grand['invoice_amount'] += $inv['invoice_amount'];
                $grand['prime_owing'] += $inv['prime_owing'];
                $grand['tax_owing'] += $inv['tax_owing'];

                $rows->push([
                    'data' => $rowData,
                ]);
            }

            $subtotalRow = [
                'Total ' . $customerName,
                '',
                '',
                '',
                number_format($subtotal['invoice_amount'] / 100, 2, ',', '.'),
                number_format($subtotal['prime_owing'] / 100, 2, ',', '.'),
                number_format($subtotal['tax_owing'] / 100, 2, ',', '.'),
                '',
            ];

            $rows->push([
                'data' => $subtotalRow,
                'style' => [
                    'font-weight' => 'bold',
                    'color' => 'rgb(6, 6, 174)',
                ],
            ]);
        }

        // Grand total across all customers (matches UI total).
        $grandRow = [
            'Total',
            '',
            '',
            '',
            number_format($grand['invoice_amount'] / 100, 2, ',', '.'),
            number_format($grand['prime_owing'] / 100, 2, ',', '.'),
            number_format($grand['tax_owing'] / 100, 2, ',', '.'),
            '',
        ];
        $rows->push([
            'data' => $grandRow,
            'style' => [
                'font-weight' => 'bold',
                'color' => 'rgb(6, 6, 174)',
            ],
        ]);

        return [
            'filename' => "{$company->name} Outstanding Invoice {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'Outstanding Invoice',
            'report_period' => "As of " . Carbon::parse($asOfDate)->format('d M Y'),
            'columns' => $columns,
            'rows' => $rows->toArray(),
        ];
    }

    public function __buildReportDetail(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $asOfDate = $this->report->getStartDate();
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $daysPerPeriod = (int) ($this->getFilterState('days_per_period') ?? 30);
        $numberOfPeriods = (int) ($this->getFilterState('number_of_periods') ?? 4);

        $dataDetail = $this->reportService->buildAgingReportInvoiceLevel(
            $this->getFormattedAsOfDate(),
            $this->getEntityType(),
            $daysPerPeriod,
            $numberOfPeriods,
            $this->company->id
        );

        $columns = $dataDetail['columns'];
        $rows = collect();
        $grouped = collect($dataDetail['rows'])->groupBy('customer_name');
        $grand = ['total_prime' => 0];

        foreach ($grouped as $customerName => $invoices) {
            $companyHeader = array_fill(0, count($columns), null);
            $companyHeader[0] = $customerName;

            $rows->push([
                'data' => $companyHeader,
                'style' => [
                    'font-weight' => 'bold',
                ],
            ]);

            $subtotal = [
                'total_prime' => 0,
            ];

            $agingKeys = array_keys($invoices->first()['aging']);
            foreach ($agingKeys as $key) {
                $subtotal[$key] = 0;
                $grand[$key] = $grand[$key] ?? 0;
            }

            foreach ($invoices as $r) {
                $rowData = [
                    $r['invoice_number'],
                    $r['invoice_date'],
                    number_format($r['total_prime'] / 100, 2, ',', '.'),
                ];

                foreach ($r['aging'] as $value) {
                    $rowData[] = number_format($value / 100, 2, ',', '.');
                }

                $subtotal['total_prime'] += $r['total_prime'];
                foreach ($r['aging'] as $key => $value) {
                    $subtotal[$key] += $value;
                }
                $grand['total_prime'] += $r['total_prime'];
                foreach ($r['aging'] as $key => $value) {
                    $grand[$key] += $value;
                }

                $rows->push([
                    'data' => $rowData,
                ]);
            }

            $subtotalRow = [
                'Total ' . $customerName,
                "",
                number_format($subtotal['total_prime'] / 100, 2, ',', '.'),
            ];

            foreach ($agingKeys as $key) {
                $subtotalRow[] = number_format($subtotal[$key] / 100, 2, ',', '.');
            }

            $rows->push([
                'data' => $subtotalRow,
                'style' => [
                    'font-weight' => 'bold',
                    'color' => 'rgb(6, 6, 174)'
                ],
            ]);
        }

        // Grand total at the end (matches UI total across all customers).
        if (! empty($grand)) {
            $agingKeys = array_keys(array_filter($grand, fn ($k) => str_starts_with((string) $k, 'period_'), ARRAY_FILTER_USE_KEY));
            sort($agingKeys);

            $grandRow = ['Total', '', number_format(($grand['total_prime'] ?? 0) / 100, 2, ',', '.')];
            foreach ($agingKeys as $key) {
                $grandRow[] = number_format(($grand[$key] ?? 0) / 100, 2, ',', '.');
            }

            $rows->push([
                'data' => $grandRow,
                'style' => ['font-weight' => 'bold', 'color' => 'rgb(6, 6, 174)'],
            ]);
        }

        return [
            'filename' => "{$company->name} Aging Receivable Detail {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'Aging Receivable Detail',
            'report_period' => "As of " . Carbon::parse($asOfDate)->format('d M Y'),
            'columns' => $columns,
            'rows' => $rows->toArray(),
        ];
    }

    public function __buildReportDefault(): array
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
        $mappedColumns = array_map(fn($col) => $columnMap[$col] ?? ucfirst(str_replace('_', ' ', $col)), $columns);
        $toNumber = fn($v) => is_null($v)
            ? 0
            : (float) str_replace(',', '.', preg_replace('/[^\d,]/', '', $v));

        $data = [
            'filename'           => "{$company->name} Aging Receivable Summary {$now}.pdf",
            'company_name'       => $company->name,
            'report_title'       => "Aging Receivable Summary",
            'report_period'      => "As of {$startFormatted}",
            'columns'            => $mappedColumns,
            'rows'               => [],
        ];

        $rows = collect();

        foreach ($report->getCategories() as $cat) {
            foreach ($cat->data as $item) {
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
                        'padding-left' => '15px',
                    ],
                ]);
            }
        }

        $totalRowData = [];
        foreach ($columns as $colKey) {
            if ($colKey === 'entity_name') {
                $totalRowData[] = $overallTotal[$colKey] ?? 'Total';
            } else {
                $totalRowData[] = $toNumber($overallTotal[$colKey] ?? 0);
            }
        }
        $rows->push([
            'data' => $totalRowData,
            'style' => [
                'font-weight' => 'bold',
                'color' => 'rgb(6, 6, 174)',
            ],
        ]);

        $data['rows'] = $rows->toArray();

        return $data;
    }

    public function exportPDF($template = null): StreamedResponse
    {
        return $this->exportService->exportToPdfEnhanced(
            data: $this->buildReportEnhanced(
                template: $template,
                format: 'pdf'
            )
        );
    }

    public function exportXLSX($template = null): BinaryFileResponse
    {
        return $this->exportService->exportToExcelEnhanced(
            data: $this->buildReportEnhanced(
                template: $template,
                format: 'xlsx'
            )
        );
    }
}

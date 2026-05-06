<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasPermissionCheck;
use App\Filament\Company\Resources\Addons\DepreciationRecordResource\Pages;
use App\Helpers\AuthHelper;
use App\Models\Accounting\Transaction;
use App\Models\Addons\Asset;
use App\Models\Addons\DepreciationRecord;
use App\Models\Company;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;

class DepreciationRecordResource extends Resource
{
    use HasPermissionCheck;

    protected static ?string $model = DepreciationRecord::class;

    /**
     * @return array<int, string>
     */
    private static function fiscalYearOptions(): array
    {
        return collect(range((int) now()->year, (int) now()->year - 10))
            ->mapWithKeys(fn ($y) => [$y => (string) $y])
            ->toArray();
    }

    /**
     * @return list<string>
     */
    private static function monthLabels(): array
    {
        return ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->columns(1)
            ->schema([
                Forms\Components\Select::make('asset_id')
                    ->label('Asset')
                    ->options(fn() => Asset::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\TextInput::make('fiscal_year')
                    ->label('Fiscal Year')
                    ->numeric()
                    ->default(now()->year)
                    ->required(),

                Forms\Components\TextInput::make('opening_fiscal_book_value')
                    ->label('Opening Book Value')
                    ->money()
                    ->required(),

                Forms\Components\TextInput::make('closing_fiscal_book_value')
                    ->label('Closing Book Value')
                    ->money()
                    ->required()
                    ->disabled(fn(Get $get) => filled($get('opening_fiscal_book_value')) && filled($get('depreciation_expense')))
                    ->dehydrated(),
            ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('asset.name')
                    ->label('Asset')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('fiscal_year')
                    ->label('Fiscal Year')
                    ->sortable(),

                Tables\Columns\TextColumn::make('opening_fiscal_book_value')
                    ->label('Opening Book Value')
                    ->currency(),

                Tables\Columns\TextColumn::make('depreciation_expense')
                    ->label('Depreciation Expense')
                    ->currency(),

                Tables\Columns\TextColumn::make('closing_fiscal_book_value')
                    ->label('Closing Book Value')
                    ->currency(),

                Tables\Columns\TextColumn::make('fiscal_method')
                    ->label('Fiscal Method')
                    ->badge(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('fiscal_year')
                    ->label('Fiscal Year')
                    ->searchable()
                    ->options(self::fiscalYearOptions()),
                Tables\Filters\SelectFilter::make('asset_id')
                    ->searchable()
                    ->label('Asset')
                    ->options(fn() => Asset::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->headerActions([
                Tables\Actions\Action::make('exportExcel')
                    ->label('Export XLSX')
                    ->icon('heroicon-o-document-arrow-down')
                    ->form([
                        Forms\Components\Select::make('template')
                            ->label('Template')
                            ->options([
                                'summary' => 'Summary',
                                'detail' => 'Detail (Per Bulan, Per Baris)',
                            ])
                            ->default('summary')
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $company = Company::find(AuthHelper::currentCompany());

                        $template = (string) ($data['template'] ?? 'summary');

                        $records = \App\Models\Addons\DepreciationRecord::with('asset.group')
                            ->where('company_id', $company->id)
                            ->orderBy('fiscal_year', 'desc')
                            ->orderBy('asset_id')
                            ->get();

                        $now = now()->format('Y-m-d_H-i-s');

                        $rows = collect();

                        if ($template === 'detail') {
                            $assetIds = $records->pluck('asset_id')->filter()->unique()->values()->all();
                            $fiscalYears = $records->pluck('fiscal_year')->filter()->unique()->values()->all();

                            $monthsByAssetYear = [];
                            if (! empty($assetIds) && ! empty($fiscalYears)) {
                                Transaction::query()
                                    ->where('company_id', $company->id)
                                    ->where('type', 'journal')
                                    ->where('meta->addon', 'asset_depreciation')
                                    ->whereIn('meta->asset_id', $assetIds)
                                    ->whereIn('meta->fiscal_year', $fiscalYears)
                                    ->get(['meta'])
                                    ->each(function (Transaction $t) use (&$monthsByAssetYear): void {
                                        $meta = (array) ($t->meta ?? []);

                                        $assetId = isset($meta['asset_id']) ? (int) $meta['asset_id'] : null;
                                        $fiscalYear = isset($meta['fiscal_year']) ? (int) $meta['fiscal_year'] : null;
                                        $fiscalMonth = isset($meta['fiscal_month']) ? (int) $meta['fiscal_month'] : null;

                                        if (! $assetId || ! $fiscalYear || ! $fiscalMonth) {
                                            return;
                                        }
                                        if ($fiscalMonth < 1 || $fiscalMonth > 12) {
                                            return;
                                        }

                                        $monthsByAssetYear[$assetId] ??= [];
                                        $monthsByAssetYear[$assetId][$fiscalYear] ??= [];
                                        $monthsByAssetYear[$assetId][$fiscalYear][$fiscalMonth] = true;
                                    });

                                foreach ($monthsByAssetYear as $assetId => $years) {
                                    foreach ($years as $year => $monthsMap) {
                                        $months = array_keys($monthsMap);
                                        sort($months);
                                        $monthsByAssetYear[$assetId][$year] = $months;
                                    }
                                }
                            }

                            $header = [
                                'No',
                                'Tahun',
                                'Bulan',
                                'Kelompok / Jenis Harta',
                                'Bulan / Tahun Perolehan',
                                'Harga Perolehan (Rp)',
                                'Nilai Sisa Buku Fiskal Awal Tahun (Rp)',
                                'Metode Komersial',
                                'Metode Fiskal',
                                'Penyusutan / Amortisasi Fiskal (Rp)',
                                'Nilai Sisa Buku Fiskal (Rp)',
                            ];

                            $rowNumber = 1;
                            $grouped = $records->groupBy(fn ($r) => $r->asset->group->name ?? 'Lain-lain');

                            $grandTotal = 0.0;

                            foreach ($grouped as $groupName => $groupRecords) {
                                $groupRecords = $groupRecords->sortBy(fn ($r) => $r->asset->id);

                                $rows->push([
                                    'data' => [$groupName],
                                    'style' => [
                                        'bold' => true,
                                        'underline' => true,
                                    ],
                                ]);

                                $groupTotal = 0.0;

                                foreach ($groupRecords as $record) {
                                    $monthly = (array) ($record->monthly_breakdown ?? []);
                                    $months = collect($monthsByAssetYear[$record->asset_id][$record->fiscal_year] ?? [])
                                        ->map(fn ($m) => (int) $m)
                                        ->filter(fn ($m) => $m >= 1 && $m <= 12)
                                        ->unique()
                                        ->sort()
                                        ->values();

                                    if ($months->isEmpty()) {
                                        $months = collect(array_keys($monthly))
                                            ->map(fn ($m) => (int) $m)
                                            ->filter(fn ($m) => $m >= 1 && $m <= 12)
                                            ->unique()
                                            ->sort()
                                            ->values();
                                    }

                                    if ($months->isEmpty()) {
                                        $acq = $record->asset?->acquisition_date;
                                        $acqDate = $acq instanceof Carbon ? $acq->copy() : ($acq ? Carbon::parse($acq) : null);

                                        if ($acqDate) {
                                            $deprStartDate = $acqDate->copy()->startOfMonth();
                                            if ((int) $acqDate->format('d') > 15) {
                                                $deprStartDate = $deprStartDate->addMonth();
                                            }

                                            $startYear = (int) $deprStartDate->format('Y');
                                            $startMonth = (int) $deprStartDate->format('m');

                                            $yearStartMonth = ((int) $record->fiscal_year === $startYear) ? $startMonth : 1;
                                            $yearEndMonth = 12;

                                            $now = now();
                                            if ((int) $record->fiscal_year === (int) $now->format('Y')) {
                                                $maxMonthInCurrentYear = $now->isLastOfMonth()
                                                    ? (int) $now->format('m')
                                                    : ((int) $now->format('m') - 1);
                                                $yearEndMonth = min($yearEndMonth, max(0, $maxMonthInCurrentYear));
                                            }

                                            if ($yearEndMonth >= $yearStartMonth && $yearEndMonth >= 1) {
                                                $months = collect(range(max(1, $yearStartMonth), min(12, $yearEndMonth)));
                                            }
                                        }
                                    }

                                    $runningBook = (float) ($record->opening_fiscal_book_value ?? 0);
                                    $assetTotal = 0.0;
                                    $yearPrinted = false;

                                    foreach ($months as $m) {
                                        $amt = (float) ($monthly[$m] ?? $monthly[(string) $m] ?? 0);
                                        $runningBook = (float) $runningBook - $amt;

                                        $amtRp = $amt / 100;
                                        $bookRp = $runningBook / 100;

                                        $groupTotal += $amtRp;
                                        $grandTotal += $amtRp;
                                        $assetTotal += $amtRp;

                                        $rows->push([
                                            'data' => [
                                                $rowNumber++,
                                                $yearPrinted ? '' : ((string) $record->fiscal_year . ' '),
                                                Carbon::create($record->fiscal_year, (int) $m, 1)->format('M'),
                                                $record->asset->name ?? '-',
                                                optional($record->asset->acquisition_date)->format('d M Y') ?? '-',
                                                ($record->asset->acquisition_cost ?? 0) / 100,
                                                ($record->opening_fiscal_book_value ?? 0) / 100,
                                                $record->commercial_method?->getLabel() ?? '-',
                                                $record->fiscal_method?->getLabel() ?? '-',
                                                $amtRp,
                                                $bookRp,
                                            ],
                                            'style' => [],
                                        ]);

                                        $yearPrinted = true;
                                    }

                                    // Total per nama harta + 1 baris spacer
                                    $rows->push([
                                        'data' => [
                                            '',
                                            '',
                                            '',
                                            'Total ' . ($record->asset->name ?? '-'),
                                            '',
                                            '',
                                            '',
                                            '',
                                            '',
                                            $assetTotal,
                                            ($record->closing_fiscal_book_value ?? 0) / 100,
                                        ],
                                        'style' => ['bold' => true],
                                    ]);
                                    $rows->push([
                                        'data' => [''],
                                        'style' => [],
                                    ]);
                                }

                                $rows->push([
                                    'data' => [
                                        '',
                                        '',
                                        '',
                                        "Subtotal {$groupName}",
                                        '',
                                        '',
                                        '',
                                        '',
                                        '',
                                        $groupTotal,
                                        '',
                                    ],
                                    'style' => ['bold' => true],
                                ]);

                                $rows->push([
                                    'data' => [''],
                                    'style' => [],
                                ]);
                                $rows->push([
                                    'data' => [''],
                                    'style' => [],
                                ]);
                            }

                            $rows->push([
                                'data' => [
                                    '',
                                    '',
                                    '',
                                    'JUMLAH PENYUSUTAN FISKAL',
                                    '',
                                    '',
                                    '',
                                    '',
                                    '',
                                    '',
                                    $grandTotal,
                                    '',
                                ],
                                'style' => ['bold' => true, 'border-top' => true],
                            ]);
                        } else {
                            $header = [
                                'No',
                                'Kelompok / Jenis Harta',
                                'Bulan / Tahun Perolehan',
                                'Harga Perolehan (Rp)',
                                'Nilai Sisa Buku Fiskal Awal Tahun (Rp)',
                                'Metode Komersial',
                                'Metode Fiskal',
                                'Penyusutan / Amortisasi Fiskal Tahun Ini (Rp)',
                                'Catatan (Nilai Sisa Buku)',
                            ];

                            $rowNumber = 1;
                            $grouped = $records->groupBy(fn ($r) => $r->asset->group->name ?? 'Lain-lain');

                            foreach ($grouped as $groupName => $groupRecords) {
                                $groupRecords = $groupRecords->sortBy(fn ($r) => $r->asset->id);

                                $rows->push([
                                    'data' => [$groupName],
                                    'style' => [
                                        'bold' => true,
                                        'underline' => true,
                                    ],
                                ]);

                                foreach ($groupRecords as $record) {
                                    $rows->push([
                                        'data' => [
                                            $rowNumber++,
                                            $record->asset->name ?? '-',
                                            optional($record->asset->acquisition_date)->format('d M Y') ?? '-',
                                            ($record->asset->acquisition_cost ?? 0) / 100,
                                            ($record->opening_fiscal_book_value ?? 0) / 100,
                                            $record->commercial_method?->getLabel() ?? '-',
                                            $record->fiscal_method?->getLabel() ?? '-',
                                            ($record->depreciation_expense ?? 0) / 100,
                                            ($record->closing_fiscal_book_value ?? 0) / 100,
                                        ],
                                        'style' => [],
                                    ]);
                                }

                                $rows->push([
                                    'data' => [
                                        '',
                                        "Subtotal {$groupName}",
                                        '',
                                        '',
                                        '',
                                        '',
                                        '',
                                        $groupRecords->sum('depreciation_expense') / 100,
                                        '',
                                    ],
                                    'style' => ['bold' => true],
                                ]);

                                $rows->push([
                                    'data' => [''],
                                    'style' => [],
                                ]);
                            }

                            $rows->push([
                                'data' => [
                                    '',
                                    'JUMLAH PENYUSUTAN FISKAL',
                                    '',
                                    '',
                                    '',
                                    '',
                                    '',
                                    $records->sum('depreciation_expense') / 100,
                                    '',
                                ],
                                'style' => ['bold' => true, 'border-top' => true],
                            ]);
                        }

                        $data = [
                            'filename'      => "{$company->name} Daftar Penyusutan {$now}.xlsx",
                            'company_name'  => $company->name,
                            'report_title'  => 'Daftar Penyusutan dan Amortisasi Fiskal',
                            'report_period' => 'As of ' . now()->format('d M Y'),
                            'columns'       => $header,
                            'rows'          => $rows->toArray(),
                            // Format angka hanya untuk kolom nominal (biar kolom Tahun/Bulan tidak jadi 2.021,00)
                            'numeric_columns' => $template === 'detail'
                                ? ['F', 'G', 'J', 'K'] // Harga, Opening, Penyusutan, Nilai Sisa
                                : ['D', 'E', 'H', 'I'], // Summary: Harga, Opening, Penyusutan, Catatan (Nilai Sisa)
                        ];

                        $exportService = new \App\Services\ExportService();

                        return $exportService->exportToExcelEnhanced(data: $data);
                    }),
                Tables\Actions\Action::make('exportPdf')
                    ->label('Export PDF')
                    ->color('danger')
                    ->icon('heroicon-o-document-arrow-down')
                    ->form([
                        Forms\Components\Select::make('template')
                            ->label('Template')
                            ->options([
                                'summary' => 'Summary',
                                'detail' => 'Detail (Per Bulan, Per Baris)',
                            ])
                            ->default('summary')
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $companyId = AuthHelper::currentCompany();
                        $company = Company::find($companyId);
                        $template = (string) ($data['template'] ?? 'summary');

                        $records = \App\Models\Addons\DepreciationRecord::with('asset.group')
                            ->where('company_id', $companyId)
                            ->orderBy('fiscal_year', 'desc')
                            ->orderBy('asset_id')
                            ->get();

                        $now = Carbon::now()->format('Y-m-d_H-i-s');
                        $filename = "{$company->name} Daftar Penyusutan {$now}.pdf";
                        $view = $template === 'detail'
                            ? 'pdf.depreciation-records-detail'
                            : 'pdf.depreciation-records';

                        $pdf = SnappyPdf::loadView($view, [
                            'company' => $company,
                            'records' => $records,
                            'report' => [
                                'fiscal_year' => now()->year,
                            ],
                        ]);

                        $pdf->setOptions([
                            'dpi' => 96,
                            'zoom' => 1,
                            'enable-local-file-access' => true,
                            'encoding' => 'UTF-8',
                            'margin-top' => 5,
                            'margin-bottom' => 5,
                            'margin-left' => 5,
                            'margin-right' => 5,
                            'disable-smart-shrinking' => true,
                            'orientation' => 'Landscape'
                        ]);

                        return response()->streamDownload(
                            fn() => print($pdf->output()),
                            $filename
                        );
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->slideOver()
                        ->modalWidth(\Filament\Support\Enums\MaxWidth::Small),
                ])
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageDepreciationRecords::route('/'),
        ];
    }
}

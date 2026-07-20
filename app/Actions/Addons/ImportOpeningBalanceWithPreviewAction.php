<?php

namespace App\Actions\Addons;

use App\Helpers\AuthHelper;
use App\Services\OpeningBalanceNameImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Throwable;

class ImportOpeningBalanceWithPreviewAction
{
    public static function make(): Action
    {
        return Action::make('importOpeningBalance')
            ->label('Import Saldo Awal')
            ->icon('heroicon-o-currency-dollar')
            ->color('primary')
            ->outlined()
            ->modalWidth(MaxWidth::FiveExtraLarge)
            ->modalHeading('Import Saldo Awal (dengan preview)')
            ->modalSubmitActionLabel('Import sekarang')
            ->steps([
                Step::make('Upload')
                    ->description('Pilih tahun dan file Excel')
                    ->schema([
                        Select::make('year')
                            ->label('Tahun (Fiscal Year)')
                            ->required()
                            ->live()
                            ->options(function () {
                                $currentYear = company_today()->year;
                                $years = range($currentYear, max($currentYear - 15, 2000));

                                return collect($years)
                                    ->mapWithKeys(fn (int $y) => [$y => "FY {$y}"])
                                    ->toArray();
                            })
                            ->searchable(),
                        FileUpload::make('file')
                            ->label('Upload Excel File')
                            ->required()
                            ->live()
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes([
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'text/csv',
                            ])
                            ->helperText(new HtmlString(
                                'Format: Account Name + Balance (by name). Template: '
                                .'<a href="'.e(url('templates/saldo-awal-kpg')).'" target="_blank" class="underline text-primary-500">unduh</a>.'
                            )),
                    ]),
                Step::make('Preview')
                    ->description('Cek exact / baru / map / skip sebelum import')
                    ->schema([
                        Placeholder::make('preview_table')
                            ->label('Hasil matching')
                            ->content(function (Get $get): HtmlString {
                                return self::renderPreviewHtml(
                                    year: (int) ($get('year') ?: 0),
                                    file: $get('file'),
                                );
                            }),
                    ]),
            ])
            ->action(function (array $data): void {
                $companyId = AuthHelper::currentCompany();
                if (! $companyId) {
                    Notification::make()->title('Company tidak aktif')->danger()->send();

                    return;
                }

                $year = (int) ($data['year'] ?? 0);
                $relative = $data['file'] ?? null;
                if ($year <= 0 || ! is_string($relative) || $relative === '') {
                    Notification::make()->title('Tahun/file wajib')->danger()->send();

                    return;
                }

                $path = Storage::disk('local')->path($relative);
                if (! is_file($path)) {
                    Notification::make()->title('File tidak ditemukan')->danger()->send();

                    return;
                }

                /** @var OpeningBalanceNameImportService $service */
                $service = app(OpeningBalanceNameImportService::class);

                try {
                    $matches = $service->matchFromExcel((int) $companyId, $path);
                    $mapping = $service->buildAutoApprovedMappingFromMatches(
                        (int) $companyId,
                        $year,
                        $path,
                        $matches,
                    );
                    $plan = $service->importApprovedMapping($mapping, execute: true);
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Import gagal')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $imported = collect($plan)->where(fn ($r) => ! in_array($r[2] ?? '', ['skip', '?'], true))->count();
                $skipped = collect($plan)->where(fn ($r) => ($r[2] ?? '') === 'skip')->count();

                Notification::make()
                    ->title('Import saldo awal selesai')
                    ->body("Diproses: {$imported} baris, di-skip: {$skipped} baris.")
                    ->success()
                    ->send();
            });
    }

    private static function renderPreviewHtml(int $year, mixed $file): HtmlString
    {
        if ($year <= 0 || ! is_string($file) || $file === '') {
            return new HtmlString('<p class="text-sm text-gray-500">Unggah file di langkah sebelumnya untuk melihat preview.</p>');
        }

        $companyId = AuthHelper::currentCompany();
        if (! $companyId) {
            return new HtmlString('<p class="text-sm text-danger-600">Company tidak aktif.</p>');
        }

        $path = Storage::disk('local')->path($file);
        if (! is_file($path)) {
            return new HtmlString('<p class="text-sm text-danger-600">File belum tersedia di storage.</p>');
        }

        try {
            /** @var OpeningBalanceNameImportService $service */
            $service = app(OpeningBalanceNameImportService::class);
            $matches = $service->matchFromExcel((int) $companyId, $path);
            $preview = $service->buildPreviewTable($matches);
        } catch (Throwable $e) {
            return new HtmlString('<p class="text-sm text-danger-600">'.e($e->getMessage()).'</p>');
        }

        $s = $preview['summary'];
        $html = '<div class="space-y-3 text-sm">';
        $html .= '<div class="flex flex-wrap gap-3">';
        $html .= self::badge('Exact → import', $s['exact'], 'success');
        $html .= self::badge('Fuzzy → map', $s['map'], 'info');
        $html .= self::badge('Baru → create', $s['create'], 'warning');
        $html .= self::badge('Skip', $s['skip'], 'gray');
        $html .= '</div>';
        $html .= '<p class="text-gray-500">Aturan v1: exact/map(1 kandidat)/create di-import; ambiguous &amp; P&amp;L di-skip. Tidak mengubah transaksi operasional.</p>';
        $html .= '<div class="overflow-auto max-h-[28rem] rounded border border-gray-200 dark:border-gray-700">';
        $html .= '<table class="w-full text-left text-xs">';
        $html .= '<thead class="sticky top-0 bg-gray-50 dark:bg-gray-800"><tr>';
        foreach (['Status', 'Nama Excel', 'Arah ke sistem', 'Rename', 'Saldo'] as $h) {
            $html .= '<th class="px-2 py-1.5 font-semibold">'.e($h).'</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($preview['rows'] as $row) {
            $tone = $row['will_import'] ? '' : ' opacity-70';
            $html .= '<tr class="border-t border-gray-100 dark:border-gray-700'.$tone.'">';
            $html .= '<td class="px-2 py-1 align-top whitespace-nowrap">'.e($row['status_label']).'</td>';
            $html .= '<td class="px-2 py-1 align-top">'.e($row['excel_name']).'</td>';
            $html .= '<td class="px-2 py-1 align-top">'.e($row['destination']).'</td>';
            $html .= '<td class="px-2 py-1 align-top">'.e($row['rename']).'</td>';
            $html .= '<td class="px-2 py-1 align-top whitespace-nowrap">'.e($row['balance']).'</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div></div>';

        return new HtmlString($html);
    }

    private static function badge(string $label, int $count, string $color): string
    {
        $classes = match ($color) {
            'success' => 'bg-success-100 text-success-800 dark:bg-success-900 dark:text-success-100',
            'info' => 'bg-info-100 text-info-800 dark:bg-info-900 dark:text-info-100',
            'warning' => 'bg-warning-100 text-warning-800 dark:bg-warning-900 dark:text-warning-100',
            default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
        };

        return '<span class="inline-flex items-center rounded px-2 py-1 '.$classes.'">'
            .e($label).': <strong class="ml-1">'.$count.'</strong></span>';
    }
}

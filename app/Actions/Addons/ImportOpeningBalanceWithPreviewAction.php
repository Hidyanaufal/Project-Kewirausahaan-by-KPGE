<?php

namespace App\Actions\Addons;

use App\Helpers\AuthHelper;
use App\Services\OpeningBalanceNameImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
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
            ->form([
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
                    ->visibility('private')
                    ->acceptedFileTypes([
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/csv',
                    ])
                    ->helperText(new HtmlString(
                        'Format: Account Name + Balance (by name). Template: '
                        .'<a href="'.e(url('templates/saldo-awal-kpg')).'" target="_blank" class="underline text-primary-500">unduh</a>.'
                    ))
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $set('resolved_path', self::resolveAbsolutePath($state));
                    }),
                Hidden::make('resolved_path')
                    ->dehydrated(),
                Placeholder::make('preview_table')
                    ->label('Hasil matching')
                    ->content(function (Get $get): HtmlString {
                        return self::renderPreviewHtml(
                            year: (int) ($get('year') ?: 0),
                            absolutePath: self::normalizeAbsolutePath($get('resolved_path'))
                                ?? self::resolveAbsolutePath($get('file')),
                        );
                    }),
            ])
            ->action(function (array $data): void {
                $companyId = AuthHelper::currentCompany();
                if (! $companyId) {
                    Notification::make()->title('Company tidak aktif')->danger()->send();

                    return;
                }

                $year = (int) ($data['year'] ?? 0);
                $path = self::normalizeAbsolutePath($data['resolved_path'] ?? null)
                    ?? self::resolveAbsolutePath($data['file'] ?? null);

                if ($year <= 0 || $path === null) {
                    Notification::make()
                        ->title('Tahun/file wajib')
                        ->body('Pilih tahun fiscal dan pastikan file Excel sudah terunggah penuh sebelum import.')
                        ->danger()
                        ->send();

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
                    Log::error('Opening balance UI import failed', [
                        'company_id' => $companyId,
                        'year' => $year,
                        'path' => $path,
                        'message' => $e->getMessage(),
                    ]);

                    Notification::make()
                        ->title('Import gagal')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $zeroed = collect($plan)->where(fn ($r) => ($r[2] ?? '') === OpeningBalanceNameImportService::ACTION_ZERO_MISSING)->count();
                $clearedObe = collect($plan)->first(fn ($r) => ($r[2] ?? '') === OpeningBalanceNameImportService::ACTION_CLEAR_OBE);
                $imported = collect($plan)->where(fn ($r) => ! in_array($r[2] ?? '', ['skip', '?', OpeningBalanceNameImportService::ACTION_ZERO_MISSING, OpeningBalanceNameImportService::ACTION_CLEAR_OBE], true))->count();
                $skipped = collect($plan)->where(fn ($r) => ($r[2] ?? '') === 'skip')->count();

                $obeNote = $clearedObe
                    ? ' OBE dinolkan ke Retained Earnings ('.($clearedObe[4] ?? '0').').'
                    : '';

                Notification::make()
                    ->title('Import saldo awal selesai')
                    ->body("Diproses: {$imported} baris, di-nolkan (di luar Excel): {$zeroed}, di-skip: {$skipped} baris.{$obeNote} P&L dan mutasi setelah awal FY tetap muncul di Trial Balance.")
                    ->success()
                    ->send();
            });
    }

    private static function renderPreviewHtml(int $year, ?string $absolutePath): HtmlString
    {
        if ($year <= 0) {
            return new HtmlString('<p class="text-sm text-warning-600">Pilih <strong>tahun fiscal</strong> dulu.</p>');
        }

        if ($absolutePath === null || ! is_file($absolutePath)) {
            return new HtmlString('<p class="text-sm text-warning-600">Unggah file Excel di atas (tunggu sampai upload selesai) untuk melihat preview.</p>');
        }

        $companyId = AuthHelper::currentCompany();
        if (! $companyId) {
            return new HtmlString('<p class="text-sm text-danger-600">Company tidak aktif.</p>');
        }

        try {
            /** @var OpeningBalanceNameImportService $service */
            $service = app(OpeningBalanceNameImportService::class);
            // Preview: matching + kandidat zero-missing (tanpa post jurnal Excel).
            $matches = $service->matchFromExcel((int) $companyId, $absolutePath);
            $preview = $service->buildPreviewTable($matches);
            $mapping = $service->buildAutoApprovedMappingFromMatches(
                (int) $companyId,
                $year,
                $absolutePath,
                $matches,
            );
            $touchedIds = $service->collectTouchedAccountIdsFromMappingRows($mapping['rows'] ?? []);
            $obeDelta = 0;
            $zeroPlan = $service->zeroAccountsAbsentFromExcel(
                (int) $companyId,
                $year,
                $touchedIds,
                execute: false,
                obeNormalDelta: $obeDelta,
            );
            $clearObePlan = $service->buildClearOpeningBalanceEquityPlanRows(
                (int) $companyId,
                $year,
                execute: false,
                obeNormalDeltaFromImport: $obeDelta,
                preferredRetainedEarningsAccountId: $service->preferredRetainedEarningsAccountIdFromMappingRows(
                    $mapping['rows'] ?? [],
                ),
            );
        } catch (Throwable $e) {
            Log::warning('Opening balance UI preview failed', [
                'company_id' => $companyId,
                'year' => $year,
                'path' => $absolutePath,
                'message' => $e->getMessage(),
            ]);

            return new HtmlString(
                '<div class="rounded border border-danger-300 bg-danger-50 dark:bg-danger-950 p-3 text-sm text-danger-700 dark:text-danger-200">'
                .'<p class="font-semibold mb-1">Preview gagal</p>'
                .'<p>'.e($e->getMessage()).'</p>'
                .'</div>'
            );
        }

        $s = $preview['summary'];
        $zeroCount = count($zeroPlan);
        $html = '<div class="space-y-3 text-sm">';
        $html .= '<div class="flex flex-wrap gap-3">';
        $html .= self::badge('Exact → import', $s['exact'], 'success');
        $html .= self::badge('Fuzzy → map', $s['map'], 'info');
        $html .= self::badge('Baru → create', $s['create'], 'warning');
        $html .= self::badge('Skip', $s['skip'], 'gray');
        $html .= self::badge('Akan di-nolkan', $zeroCount, 'warning');
        $html .= self::badge('Clear OBE', 1, 'info');
        $html .= '</div>';
        $html .= '<p class="text-gray-500">Aturan: exact/map(1 kandidat)/create di-import; ambiguous di-skip. Saldo bertanda minus = <strong>credit</strong>. '
            .'Akun leaf di luar Excel yang masih punya saldo awal FY akan di-nolkan lewat jurnal penyesuaian. '
            .'Jika Excel <strong>tidak</strong> punya Laba Ditahan/RE, residual <strong>Opening Balance Equity</strong> dinolkan ke Retained Earnings. '
            .'Jika Excel sudah punya Laba Ditahan/RE, clear OBE <strong>dilewati</strong> agar angka Excel tidak tertimpa — sisa OBE tetap akun clearing (disembunyikan di TB/BS). '
            .'P&amp;L dan mutasi setelah awal FY tetap muncul di Trial Balance.</p>';
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

        $html .= '</tbody></table></div>';
        $html .= self::renderZeroMissingPreviewHtml($zeroPlan);
        $html .= self::renderClearObePreviewHtml($clearObePlan);
        $html .= '</div>';

        return new HtmlString($html);
    }

    /**
     * @param  list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed}>  $zeroPlan
     */
    private static function renderZeroMissingPreviewHtml(array $zeroPlan): string
    {
        if ($zeroPlan === []) {
            return '<p class="text-gray-500">Tidak ada akun neraca di luar Excel yang perlu di-nolkan (starting balance FY sudah 0 / tidak ada history pre-FY).</p>';
        }

        $shown = array_slice($zeroPlan, 0, 15);
        $remaining = count($zeroPlan) - count($shown);

        $html = '<div class="space-y-2">';
        $html .= '<p class="font-medium">Akan di-nolkan ('.count($zeroPlan).' akun neraca di luar Excel)</p>';
        $html .= '<div class="overflow-auto max-h-[14rem] rounded border border-warning-200 dark:border-warning-800">';
        $html .= '<table class="w-full text-left text-xs">';
        $html .= '<thead class="sticky top-0 bg-warning-50 dark:bg-warning-950"><tr>';
        foreach (['Akun', 'Detail', 'Penyesuaian'] as $h) {
            $html .= '<th class="px-2 py-1.5 font-semibold">'.e($h).'</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($shown as $row) {
            $html .= '<tr class="border-t border-gray-100 dark:border-gray-700">';
            $html .= '<td class="px-2 py-1 align-top">'.e((string) ($row[1] ?? '')).'</td>';
            $html .= '<td class="px-2 py-1 align-top">'.e((string) ($row[3] ?? '')).'</td>';
            $html .= '<td class="px-2 py-1 align-top whitespace-nowrap">'.e((string) ($row[4] ?? '')).'</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        if ($remaining > 0) {
            $html .= '<p class="text-gray-500">…dan '.$remaining.' akun lainnya.</p>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param  list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed}>  $clearPlan
     */
    private static function renderClearObePreviewHtml(array $clearPlan): string
    {
        $row = $clearPlan[0] ?? null;
        if ($row === null) {
            return '';
        }

        $detail = (string) ($row[3] ?? '');
        $skipped = str_contains(mb_strtolower($detail), 'dilewati');

        $html = '<div class="rounded border border-info-200 dark:border-info-800 bg-info-50/50 dark:bg-info-950/30 p-3 space-y-1">'
            .'<p class="font-medium">Langkah terakhir: Clear Opening Balance Equity</p>'
            .'<p class="text-gray-600 dark:text-gray-300">'
            .e($detail).' — penyesuaian: <strong>'.e((string) ($row[4] ?? '0')).'</strong>'
            .'</p>';

        if ($skipped) {
            $html .= '<p class="text-gray-500 text-xs">Excel sudah memuat Laba Ditahan/RE sebagai sumber akhir, jadi OBE tidak dinolkan (agar angka Excel tidak tertimpa). Sisa OBE tetap akun clearing dan disembunyikan dari Trial Balance / Balance Sheet.</p>';
        } else {
            $html .= '<p class="text-gray-500 text-xs">OBE tidak diisi dari Excel; sisa clearing setelah import otomatis ditutup ke Retained Earnings agar saldo awal FY bersih.</p>';
        }

        return $html.'</div>';
    }

    /**
     * @return string|null Absolute filesystem path to a readable Excel file.
     */
    private static function resolveAbsolutePath(mixed $file): ?string
    {
        if (is_array($file)) {
            $file = Arr::first($file);
        }

        if ($file instanceof TemporaryUploadedFile) {
            try {
                $stored = $file->store('imports', 'local');
                if (is_string($stored) && $stored !== '') {
                    return self::normalizeAbsolutePath($stored);
                }
            } catch (Throwable $e) {
                Log::warning('Failed storing TemporaryUploadedFile for OB import', [
                    'message' => $e->getMessage(),
                ]);
            }

            foreach ([$file->getRealPath(), $file->getPathname()] as $candidate) {
                if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                    return $candidate;
                }
            }

            return null;
        }

        if (! is_string($file)) {
            return null;
        }

        return self::normalizeAbsolutePath($file);
    }

    private static function normalizeAbsolutePath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (is_file($path)) {
            return $path;
        }

        $fromDisk = Storage::disk('local')->path($path);
        if (is_file($fromDisk)) {
            return $fromDisk;
        }

        return null;
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

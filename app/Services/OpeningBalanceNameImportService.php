<?php

namespace App\Services;

use App\DTO\OpeningBalanceNameMatchDTO;
use App\Enums\Accounting\JournalEntryType;
use App\Helpers\AccountTypeHelper;
use App\Models\Accounting\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use App\Models\Company;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class OpeningBalanceNameImportService
{
    public const FUZZY_THRESHOLD = 85.0;

    public const SKIP_REASON_COMPUTED_CURRENT_YEAR_EARNINGS = 'computed_current_year_earnings';

    public const SKIP_REASON_COMPUTED_CURRENT_YEAR_EARNINGS_LABEL = 'Tidak perlu di-import: Laba Tahun Berjalan dihitung otomatis di neraca (Revenue-Expense), bukan dari saldo OB Excel.';

    public const SKIP_REASON_NOMINAL_PNL = 'nominal_pnl';

    public const SKIP_REASON_NOMINAL_PNL_LABEL = 'Tidak di-import sebagai saldo awal: akun pendapatan/biaya (P&L) mematahkan persamaan neraca karena counter Open Balance Equity diexclude dari total.';

    public function __construct(
        protected AccountService $accountService,
        protected AccountParentResolutionService $parentResolution,
        protected AccountCreationService $accountCreation,
    ) {}

    /**
     * @return list<array{
     *     row_number: int,
     *     account_no: ?string,
     *     sub_account_no: ?string,
     *     account_name: string,
     *     account_type: ?string,
     *     balance_raw: mixed,
     *     is_leaf: bool,
     *     excel_parent_account_no: ?string,
     *     excel_parent_name: ?string,
     *     excel_parent_account_type: ?string,
     *     excel_parent_row_number: ?int
     * }>
     */
    public function readExcelRows(string $excelPath): array
    {
        $spreadsheet = IOFactory::load($excelPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if ($rows === []) {
            return [];
        }

        $headerRow = array_shift($rows);
        $headers = [];
        foreach ($headerRow as $col => $value) {
            $headers[$col] = $this->normalizeHeader((string) ($value ?? ''));
        }

        $parsed = [];
        $rowNumber = 1;
        $currentParent = [
            'account_no' => null,
            'name' => null,
            'account_type' => null,
            'row_number' => null,
        ];

        foreach ($rows as $row) {
            $rowNumber++;
            $mapped = [];
            foreach ($headers as $col => $header) {
                if ($header === '') {
                    continue;
                }
                $mapped[$header] = $row[$col] ?? null;
            }

            $name = trim((string) ($mapped['account_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $accountNo = $this->nullableTrim($mapped['account_no'] ?? null);
            $subAccountNo = $this->nullableTrim($mapped['sub_account_no'] ?? null);
            $accountType = $this->nullableTrim($mapped['account_type'] ?? null);
            $isLeaf = $subAccountNo !== null && $subAccountNo !== '';

            if (! $isLeaf && $accountNo !== null) {
                $currentParent = [
                    'account_no' => $accountNo,
                    'name' => $name,
                    'account_type' => $accountType,
                    'row_number' => $rowNumber,
                ];
            }

            $parsed[] = [
                'row_number' => $rowNumber,
                'account_no' => $accountNo,
                'sub_account_no' => $subAccountNo,
                'account_name' => $name,
                'account_type' => $accountType,
                'balance_raw' => $mapped['balance'] ?? null,
                'is_leaf' => $isLeaf,
                'excel_parent_account_no' => $isLeaf ? $currentParent['account_no'] : null,
                'excel_parent_name' => $isLeaf ? $currentParent['name'] : null,
                'excel_parent_account_type' => $isLeaf ? $currentParent['account_type'] : null,
                'excel_parent_row_number' => $isLeaf ? $currentParent['row_number'] : null,
            ];
        }

        return $parsed;
    }

    /**
     * @return Collection<int, OpeningBalanceNameMatchDTO>
     */
    public function matchFromExcel(int $companyId, string $excelPath): Collection
    {
        return $this->match($companyId, $this->readExcelRows($excelPath));
    }

    /**
     * @param  list<array<string, mixed>>  $excelRows
     * @return Collection<int, OpeningBalanceNameMatchDTO>
     */
    public function match(int $companyId, array $excelRows): Collection
    {
        $accounts = Account::query()
            ->where('company_id', $companyId)
            ->get(['id', 'code', 'name', 'parent_id']);

        $byNormalizedName = $accounts
            ->groupBy(fn (Account $account) => $this->normalizeName($account->name));

        $currency = CurrencyAccessor::getDefaultCurrency();
        $results = collect();
        $reservedCodes = collect();

        foreach ($excelRows as $row) {
            $dto = $this->matchRow($row, $byNormalizedName, $accounts, $currency, $companyId, $reservedCodes);
            if ($dto->status === 'new' && $dto->suggestedCode) {
                $reservedCodes->push($dto->suggestedCode);
            }
            $results->push($dto);
        }

        return $results;
    }

    /**
     * @param  Collection<int, OpeningBalanceNameMatchDTO>  $matches
     * @return array<string, mixed>
     */
    public function buildDraftMapping(int $companyId, int $year, string $excelPath, Collection $matches): array
    {
        // Parent/zero tetap dibuang dari draft; computed LTJ ikut mapping agar decide/execute menampilkan skip + alasan.
        $rows = $matches
            ->reject(fn (OpeningBalanceNameMatchDTO $dto) => in_array($dto->status, ['skipped_parent', 'skipped_zero'], true))
            ->map(fn (OpeningBalanceNameMatchDTO $dto) => $dto->toMappingRow())
            ->values()
            ->all();

        return [
            'version' => 1,
            'approved' => false,
            'company_id' => $companyId,
            'year' => $year,
            'excel_path' => $excelPath,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return list<string>
     */
    public function validateApprovedMapping(array $mapping): array
    {
        $errors = [];

        if (($mapping['approved'] ?? false) !== true) {
            $errors[] = 'Mapping belum approved. Jalankan decide terlebih dahulu.';
        }

        foreach ($mapping['rows'] ?? [] as $index => $row) {
            $label = $row['excel_name'] ?? "row#{$index}";
            $decision = $row['decision_status'] ?? null;
            $action = $row['action'] ?? null;

            if (! in_array($decision, ['decided', 'skipped'], true)) {
                $errors[] = "{$label}: decision_status masih '{$decision}' (harus decided/skipped).";

                continue;
            }

            if ($decision === 'skipped' || $action === 'skip') {
                continue;
            }

            if (! in_array($action, ['import', 'map', 'create', 'create_parent'], true)) {
                $errors[] = "{$label}: action tidak valid ({$action}).";

                continue;
            }

            if (in_array($action, ['import', 'map'], true)) {
                if (empty($row['account_id'])) {
                    $errors[] = "{$label}: account_id wajib untuk action {$action}.";
                }
                if (! array_key_exists('rename_to_excel', $row) || ! is_bool($row['rename_to_excel'])) {
                    $errors[] = "{$label}: rename_to_excel harus boolean.";
                }
            }

            if ($action === 'create') {
                if (empty($row['code'])) {
                    $errors[] = "{$label}: code wajib untuk create.";
                }
                if (empty($row['account_type']) && empty($row['excel_account_type'])) {
                    $errors[] = "{$label}: account_type wajib untuk create.";
                }
                $createType = (string) ($row['account_type'] ?? $row['excel_account_type'] ?? '');
                if ($createType !== '' && $this->isNominalPnLAccountTypeLabel($createType)) {
                    $errors[] = "{$label}: tidak boleh create/import saldo awal untuk akun pendapatan/biaya ({$createType}).";
                }
                $parentAction = $row['parent_action'] ?? 'none';
                if ($parentAction === 'use_existing' && empty($row['parent_account_id'])) {
                    $errors[] = "{$label}: parent_account_id wajib untuk parent_action use_existing.";
                }
                if ($parentAction === 'create_parent') {
                    if (empty($row['excel_parent_account_no']) || empty($row['excel_parent_name'])) {
                        $errors[] = "{$label}: excel_parent_account_no/name wajib untuk create_parent.";
                    }
                }
            }

            if ($action === 'create_parent') {
                if (empty($row['code'])) {
                    $errors[] = "{$label}: code wajib untuk create_parent.";
                }
                if (empty($row['account_type']) && empty($row['excel_account_type'])) {
                    $errors[] = "{$label}: account_type wajib untuk create_parent.";
                }
            }

            if (in_array($action, ['import', 'map'], true) && ! empty($row['account_id'])) {
                $account = Account::query()->find((int) $row['account_id']);
                if ($account && $this->isNominalPnLAccount($account)) {
                    $errors[] = "{$label}: tidak boleh import saldo awal ke akun pendapatan/biaya ({$account->code} {$account->name}).";
                }
            }
        }

        return $errors;
    }

    /**
     * Auto-approve matches for UI/batch: exact→import, fuzzy(1)→map, new→create (+ create_parent), else skip.
     *
     * @param  Collection<int, OpeningBalanceNameMatchDTO>  $matches
     * @return array<string, mixed>
     */
    public function buildAutoApprovedMappingFromMatches(
        int $companyId,
        int $year,
        string $excelPath,
        Collection $matches,
    ): array {
        $rows = [];

        foreach ($matches as $dto) {
            if (str_starts_with($dto->status, 'skipped')) {
                $rows[] = [
                    ...$dto->toMappingRow(),
                    'decision_status' => 'skipped',
                    'action' => 'skip',
                ];

                continue;
            }

            if ($dto->status === 'exact' && $dto->accountId) {
                $rows[] = [
                    ...$dto->toMappingRow(),
                    'decision_status' => 'decided',
                    'action' => 'import',
                    'rename_to_excel' => false,
                ];

                continue;
            }

            if ($dto->status === 'fuzzy' && count($dto->candidates) === 1) {
                $candidate = $dto->candidates[0];
                $rows[] = [
                    ...$dto->toMappingRow(),
                    'decision_status' => 'decided',
                    'action' => 'map',
                    'account_id' => $candidate['id'],
                    'rename_to_excel' => false,
                ];

                continue;
            }

            if ($dto->status === 'new') {
                if ($this->isNominalPnLAccountTypeLabel($dto->excelAccountType)) {
                    $rows[] = [
                        ...$dto->toMappingRow(),
                        'decision_status' => 'skipped',
                        'action' => 'skip',
                        'skip_reason' => self::SKIP_REASON_NOMINAL_PNL,
                        'status' => 'skipped_nominal_pnl',
                    ];

                    continue;
                }

                $rows[] = [
                    ...$dto->toMappingRow(),
                    'decision_status' => 'decided',
                    'action' => 'create',
                    'code' => $dto->suggestedCode ?? $dto->excelCode,
                    'account_type' => $dto->excelAccountType,
                    'rename_to_excel' => false,
                    'parent_action' => $dto->parentAction ?? 'none',
                    'parent_account_id' => $dto->parentAccountId,
                ];

                continue;
            }

            $rows[] = [
                ...$dto->toMappingRow(),
                'decision_status' => 'skipped',
                'action' => 'skip',
                'skip_reason' => $dto->skipReason ?? 'ambiguous_or_pending',
            ];
        }

        $rows = $this->injectCreateParentRows($rows);

        return [
            'version' => 1,
            'approved' => true,
            'approved_at' => now()->toIso8601String(),
            'company_id' => $companyId,
            'year' => $year,
            'excel_path' => $excelPath,
            'auto_approved' => true,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function injectCreateParentRows(array $rows): array
    {
        $parents = [];
        foreach ($rows as $row) {
            if (($row['action'] ?? null) !== 'create') {
                continue;
            }
            if (($row['parent_action'] ?? null) !== 'create_parent') {
                continue;
            }

            $key = ($row['excel_parent_account_no'] ?? '') . '|' . ($row['excel_parent_name'] ?? '');
            if ($key === '|' || isset($parents[$key])) {
                continue;
            }

            $parents[$key] = [
                'row_number' => $row['excel_parent_row_number'] ?? 0,
                'excel_name' => $row['excel_parent_name'],
                'excel_code' => $row['excel_parent_account_no'],
                'excel_account_type' => $row['excel_parent_account_type'] ?? $row['account_type'] ?? null,
                'balance_minor' => 0,
                'status' => 'new_parent',
                'decision_status' => 'decided',
                'action' => 'create_parent',
                'code' => $row['excel_parent_account_no'],
                'account_type' => $row['excel_parent_account_type'] ?? $row['account_type'] ?? null,
                'account_id' => null,
                'rename_to_excel' => false,
                'parent_action' => 'create_parent',
            ];
        }

        return array_values(array_merge(array_values($parents), $rows));
    }

    /**
     * Preview rows for UI: human-readable status and destination.
     *
     * @param  Collection<int, OpeningBalanceNameMatchDTO>  $matches
     * @return array{
     *     summary: array{exact: int, map: int, create: int, skip: int},
     *     rows: list<array{status_label: string, excel_name: string, destination: string, rename: string, balance: string, will_import: bool}>
     * }
     */
    public function buildPreviewTable(Collection $matches): array
    {
        $summary = ['exact' => 0, 'map' => 0, 'create' => 0, 'skip' => 0];
        $rows = [];

        foreach ($matches as $dto) {
            $balance = CurrencyConverter::formatCentsToMoney($dto->balanceMinor);
            $willImport = false;
            $rename = '—';
            $statusLabel = $dto->status;
            $destination = '—';

            if (str_starts_with($dto->status, 'skipped')) {
                $summary['skip']++;
                $statusLabel = 'Skip';
                $destination = $this->formatSkipReason($dto->skipReason);
            } elseif ($dto->status === 'exact' && $dto->accountId) {
                $summary['exact']++;
                $statusLabel = 'Exact → import';
                $account = Account::query()->find($dto->accountId);
                $destination = $account
                    ? "{$account->code} {$account->name} (id={$account->id})"
                    : "account_id={$dto->accountId}";
                $willImport = true;
                $rename = 'tidak';
            } elseif ($dto->status === 'fuzzy' && count($dto->candidates) === 1) {
                $summary['map']++;
                $statusLabel = 'Fuzzy → map';
                $c = $dto->candidates[0];
                $destination = "{$c['code']} {$c['name']} (id={$c['id']}, {$c['similarity']}%)";
                $willImport = true;
                $rename = 'tidak';
            } elseif ($dto->status === 'new') {
                if ($this->isNominalPnLAccountTypeLabel($dto->excelAccountType)) {
                    $summary['skip']++;
                    $statusLabel = 'Skip';
                    $destination = $this->formatSkipReason(self::SKIP_REASON_NOMINAL_PNL);
                } else {
                    $summary['create']++;
                    $statusLabel = 'Baru → create';
                    $parent = match ($dto->parentAction) {
                        'use_existing' => 'parent existing '.$dto->parentSystemCode.' '.$dto->parentSystemName,
                        'create_parent' => 'parent create '.$dto->excelParentAccountNo.' '.$dto->excelParentName,
                        default => 'tanpa parent',
                    };
                    $destination = 'create code='.($dto->suggestedCode ?? $dto->excelCode).' type='.$dto->excelAccountType.' ('.$parent.')';
                    $willImport = true;
                }
            } else {
                $summary['skip']++;
                $statusLabel = 'Skip (ambiguous/pending)';
                $destination = count($dto->candidates) > 0
                    ? 'butuh decide manual ('.count($dto->candidates).' kandidat)'
                    : 'tidak cocok otomatis';
            }

            $rows[] = [
                'status_label' => $statusLabel,
                'excel_name' => $dto->excelName,
                'destination' => $destination,
                'rename' => $rename,
                'balance' => $balance,
                'will_import' => $willImport,
            ];
        }

        return ['summary' => $summary, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed}>
     */
    public function importApprovedMapping(array $mapping, bool $execute = false): array
    {
        $errors = $this->validateApprovedMapping($mapping);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' | ', $errors));
        }

        $companyId = (int) ($mapping['company_id'] ?? 0);
        $year = (int) ($mapping['year'] ?? 2026);
        $rows = $mapping['rows'] ?? [];

        if ($execute) {
            $plan = [];
            DB::transaction(function () use ($rows, $companyId, $year, &$plan) {
                $plan = $this->processImportRows($rows, $companyId, $year, true);
            });

            return $plan;
        }

        return $this->processImportRows($rows, $companyId, $year, false);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed}>
     */
    public function processImportRows(array $rows, int $companyId, int $year, bool $execute): array
    {
        $ordered = collect($rows)->sortBy(function (array $row) {
            $action = $row['action'] ?? '';

            return match ($action) {
                'create_parent' => 0,
                'create' => 1,
                'import', 'map' => 2,
                default => 3,
            };
        })->values()->all();

        /** @var array<string, Account> $createdParentsByKey */
        $createdParentsByKey = [];
        $plan = [];

        foreach ($ordered as $row) {
            $action = $row['action'] ?? null;
            $name = $row['excel_name'] ?? '';
            $rowNumber = $row['row_number'] ?? '';
            $desired = (int) ($row['balance_minor'] ?? 0);

            if (($row['decision_status'] ?? '') === 'skipped' || $action === 'skip') {
                $reason = $this->formatSkipReason($row['skip_reason'] ?? null);
                $plan[] = [$rowNumber, $name, 'skip', $reason, '-'];

                continue;
            }

            $account = null;
            $detail = '';

            if ($action === 'create_parent') {
                $code = (string) ($row['code'] ?? $row['excel_code'] ?? '');
                $type = (string) ($row['account_type'] ?? $row['excel_account_type'] ?? '');
                $detail = "create_parent code={$code} type={$type} (no OB)";

                if ($execute) {
                    $account = $this->accountCreation->createParentAccount($companyId, $name, $code, $type);
                    $detail .= " → id={$account->id}";
                    $key = $code . '|' . $name;
                    $createdParentsByKey[$key] = $account;
                }

                $plan[] = [$rowNumber, $name, 'create_parent', $detail, '-'];

                continue;
            }

            if ($action === 'create') {
                $code = (string) ($row['code'] ?? $row['excel_code'] ?? '');
                $type = (string) ($row['account_type'] ?? $row['excel_account_type'] ?? '');

                if ($this->isNominalPnLAccountTypeLabel($type)) {
                    $plan[] = [$rowNumber, $name, 'skip', $this->formatSkipReason(self::SKIP_REASON_NOMINAL_PNL), '-'];

                    continue;
                }

                $parentAction = $row['parent_action'] ?? 'none';
                $parentId = $row['parent_account_id'] ?? null;
                $detail = "create code={$code} type={$type}";

                if ($parentAction === 'use_existing' && $parentId) {
                    $parent = Account::query()->where('company_id', $companyId)->find($parentId);
                    $detail .= ' parent=' . ($parent?->code ?? $parentId);
                } elseif ($parentAction === 'create_parent') {
                    $parentKey = ($row['excel_parent_account_no'] ?? '') . '|' . ($row['excel_parent_name'] ?? '');
                    $detail .= " parent=create({$row['excel_parent_account_no']} {$row['excel_parent_name']})";
                    if ($execute && isset($createdParentsByKey[$parentKey])) {
                        $parentId = $createdParentsByKey[$parentKey]->id;
                    } elseif ($execute) {
                        $parent = $this->accountCreation->createParentAccount(
                            $companyId,
                            (string) $row['excel_parent_name'],
                            (string) $row['excel_parent_account_no'],
                            (string) ($row['excel_parent_account_type'] ?? $type),
                        );
                        $createdParentsByKey[$parentKey] = $parent;
                        $parentId = $parent->id;
                    }
                }

                if ($execute) {
                    if ($parentId) {
                        $parent = Account::query()->where('company_id', $companyId)->findOrFail($parentId);
                        $account = $this->accountCreation->createLeafAccountUnderParent(
                            $companyId,
                            $name,
                            $code,
                            $type,
                            $parent,
                        );
                    } else {
                        $account = $this->accountCreation->createLeafAccount(
                            $companyId,
                            $name,
                            $code,
                            $type,
                        );
                    }
                    $detail .= " → id={$account->id}";
                }
            } elseif (in_array($action, ['import', 'map'], true)) {
                $accountId = (int) $row['account_id'];
                $account = Account::query()->where('company_id', $companyId)->findOrFail($accountId);
                $detail = "{$action} id={$account->id} {$account->code} {$account->name}";

                if (($row['rename_to_excel'] ?? false) === true) {
                    $detail .= " | rename → {$name}";
                    if ($execute && $account->name !== $name) {
                        $account->update(['name' => $name]);
                    }
                }
            } else {
                $plan[] = [$rowNumber, $name, $action ?? '?', 'invalid action', '-'];

                continue;
            }

            $diffLabel = '-';
            if ($execute && $account) {
                if ($this->isNominalPnLAccount($account)) {
                    $plan[] = [$rowNumber, $name, 'skip', $this->formatSkipReason(self::SKIP_REASON_NOMINAL_PNL), '-'];

                    continue;
                }
                $result = $this->postOpeningBalanceAdjustment($companyId, $account, $year, $desired);
                $diffLabel = CurrencyConverter::formatCentsToMoney($result['diff']);
                if (! $result['posted']) {
                    $diffLabel .= ' (no post)';
                }
            } elseif ($account) {
                if ($this->isNominalPnLAccount($account)) {
                    $plan[] = [$rowNumber, $name, 'skip', $this->formatSkipReason(self::SKIP_REASON_NOMINAL_PNL), '-'];

                    continue;
                }
                $preview = $this->previewAdjustmentDiff($companyId, $account, $year, $desired);
                $diffLabel = CurrencyConverter::formatCentsToMoney($preview['diff']);
            } elseif ($action === 'create') {
                $typeLabel = (string) ($row['account_type'] ?? $row['excel_account_type'] ?? '');
                if ($this->isNominalPnLAccountTypeLabel($typeLabel)) {
                    $plan[] = [$rowNumber, $name, 'skip', $this->formatSkipReason(self::SKIP_REASON_NOMINAL_PNL), '-'];

                    continue;
                }
                $diffLabel = CurrencyConverter::formatCentsToMoney($desired) . ' (new)';
            }

            $plan[] = [$rowNumber, $name, $action, $detail, $diffLabel];
        }

        return $plan;
    }

    public function computeFiscalYearStartForYear(Company $company, int $year): Carbon
    {
        $currentYear = company_today()->year;
        $baseStart = Carbon::parse($company->locale->fiscalYearStartDate())->startOfDay();

        return $baseStart->subYears($currentYear - $year)->startOfDay();
    }

    public function parseBalanceToMinor(string | float | int | null $raw, ?string $currency = null): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }

        $currency ??= CurrencyAccessor::getDefaultCurrency();

        if (is_string($raw)) {
            $raw = trim($raw);
            $raw = str_replace([' ', "\u{00A0}"], '', $raw);
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        }

        return CurrencyConverter::convertToCents((float) $raw, $currency);
    }

    /**
     * Post adjustment so starting balance at FY start equals desiredMinor.
     *
     * @return array{posted: bool, diff: int, transaction_id: ?int}
     */
    public function postOpeningBalanceAdjustment(
        int $companyId,
        Account $account,
        int $year,
        int $desiredMinor,
    ): array {
        if ($account->isParentAccount()) {
            return ['posted' => false, 'diff' => 0, 'transaction_id' => null];
        }

        if ($this->isNominalPnLAccount($account)) {
            return ['posted' => false, 'diff' => 0, 'transaction_id' => null];
        }

        $company = Company::query()->findOrFail($companyId);
        $fyStart = $this->computeFiscalYearStartForYear($company, $year);
        $postedAt = $fyStart->copy()->subSecond()->toDateTimeString();
        $startDateTime = $fyStart->toDateTimeString();

        $currentStarting = $this->accountService->getStartingBalance(
            $account,
            $startDateTime,
            override: true,
            projectId: null,
            companyId: $companyId,
        );
        $currentMinor = $currentStarting?->getAmount() ?? 0;
        $diff = $desiredMinor - $currentMinor;

        if ($diff === 0) {
            return ['posted' => false, 'diff' => 0, 'transaction_id' => null];
        }

        $openingEquity = Account::getOpeningBalanceAccount($companyId);
        [$accountEntryType, $equityEntryType] = $this->determineEntryTypes($account, $diff);
        $amount = abs($diff);
        $transactionId = null;

        DB::transaction(function () use (
            $companyId,
            $account,
            $openingEquity,
            $postedAt,
            $amount,
            $accountEntryType,
            $equityEntryType,
            $year,
            &$transactionId,
        ) {
            $transaction = Transaction::create([
                'company_id' => $companyId,
                'bank_account_id' => null,
                'type' => 'journal',
                'description' => "Saldo Awal FY-{$year} (Import Adjust)",
                'posted_at' => $postedAt,
                'amount' => $amount,
                'reviewed' => false,
                'meta' => [
                    'addon' => 'opening_balance_import',
                    'year' => $year,
                    'account_code' => $account->code,
                ],
            ]);

            $transactionId = $transaction->id;

            JournalEntry::create([
                'company_id' => $companyId,
                'transaction_id' => $transaction->id,
                'account_id' => $account->id,
                'type' => $accountEntryType,
                'amount' => $amount,
                'description' => "Saldo Awal FY-{$year} - {$account->code}",
            ]);

            JournalEntry::create([
                'company_id' => $companyId,
                'transaction_id' => $transaction->id,
                'account_id' => $openingEquity->id,
                'type' => $equityEntryType,
                'amount' => $amount,
                'description' => "Saldo Awal FY-{$year} (Counter)",
            ]);
        });

        return ['posted' => true, 'diff' => $diff, 'transaction_id' => $transactionId];
    }

    /**
     * Preview diff without writing.
     *
     * @return array{diff: int, current_minor: int, desired_minor: int}
     */
    public function previewAdjustmentDiff(
        int $companyId,
        Account $account,
        int $year,
        int $desiredMinor,
    ): array {
        $company = Company::query()->findOrFail($companyId);
        $fyStart = $this->computeFiscalYearStartForYear($company, $year);
        $startDateTime = $fyStart->toDateTimeString();

        $currentStarting = $this->accountService->getStartingBalance(
            $account,
            $startDateTime,
            override: true,
            projectId: null,
            companyId: $companyId,
        );
        $currentMinor = $currentStarting?->getAmount() ?? 0;

        return [
            'diff' => $desiredMinor - $currentMinor,
            'current_minor' => $currentMinor,
            'desired_minor' => $desiredMinor,
        ];
    }

    public function normalizeName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = str_replace(['.', ',', '(', ')', '-', '/'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    public function formatSkipReason(?string $reason): string
    {
        return match ($reason) {
            self::SKIP_REASON_COMPUTED_CURRENT_YEAR_EARNINGS => self::SKIP_REASON_COMPUTED_CURRENT_YEAR_EARNINGS_LABEL,
            self::SKIP_REASON_NOMINAL_PNL => self::SKIP_REASON_NOMINAL_PNL_LABEL,
            'parent_row' => 'Baris parent (bukan leaf)',
            'zero_balance' => 'Saldo 0',
            'ambiguous_or_pending' => 'Ambiguous / perlu decide manual',
            default => $reason ?? '-',
        };
    }

    /**
     * Current Year Earnings / Laba Tahun Berjalan — equity computed, jangan post OB.
     */
    public function isComputedCurrentYearEarningsName(string $name): bool
    {
        $normalized = $this->normalizeName($name);

        return in_array($normalized, [
            'current earning of the year',
            'current earnings of the year',
            'current year earnings',
            'current year earning',
            'laba tahun berjalan',
        ], true);
    }

    public function isNominalPnLAccount(Account $account): bool
    {
        $category = $account->category ?? $account->type?->getCategory();

        return $category !== null && $category->isNominal();
    }

    public function isNominalPnLAccountTypeLabel(?string $accountTypeLabel): bool
    {
        if ($accountTypeLabel === null || trim($accountTypeLabel) === '') {
            return false;
        }

        $mapped = AccountTypeHelper::map(trim($accountTypeLabel));

        return $mapped !== null && $mapped->getCategory()->isNominal();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<string, Collection<int, Account>>  $byNormalizedName
     * @param  Collection<int, Account>  $accounts
     */
    /**
     * @param  Collection<string, Collection<int, Account>>  $byNormalizedName
     * @param  Collection<int, Account>  $accounts
     * @param  Collection<int, string>  $reservedCodes
     */
    private function matchRow(
        array $row,
        Collection $byNormalizedName,
        Collection $accounts,
        string $currency,
        int $companyId,
        Collection $reservedCodes,
    ): OpeningBalanceNameMatchDTO {
        $rowNumber = (int) $row['row_number'];
        $excelName = (string) $row['account_name'];
        $excelCode = $row['is_leaf']
            ? ($row['sub_account_no'] ?? null)
            : ($row['account_no'] ?? null);
        $accountType = $row['account_type'] ?? null;
        $balanceMinor = $this->parseBalanceToMinor($row['balance_raw'] ?? null, $currency);
        $excelParentName = $row['excel_parent_name'] ?? null;
        $excelParentAccountNo = $row['excel_parent_account_no'] ?? null;
        $excelParentAccountType = $row['excel_parent_account_type'] ?? null;
        $excelParentRowNumber = isset($row['excel_parent_row_number']) ? (int) $row['excel_parent_row_number'] : null;

        if (! ($row['is_leaf'] ?? false)) {
            return new OpeningBalanceNameMatchDTO(
                rowNumber: $rowNumber,
                excelName: $excelName,
                excelCode: $excelCode,
                excelAccountType: $accountType,
                balanceMinor: $balanceMinor,
                status: 'skipped_parent',
                decisionStatus: 'skipped',
                action: 'skip',
                skipReason: 'parent_row',
            );
        }

        if ($this->isComputedCurrentYearEarningsName($excelName)) {
            return new OpeningBalanceNameMatchDTO(
                rowNumber: $rowNumber,
                excelName: $excelName,
                excelCode: $excelCode,
                excelAccountType: $accountType,
                balanceMinor: $balanceMinor,
                status: 'skipped_computed_equity',
                decisionStatus: 'skipped',
                action: 'skip',
                skipReason: self::SKIP_REASON_COMPUTED_CURRENT_YEAR_EARNINGS,
                excelParentName: $excelParentName,
                excelParentAccountNo: $excelParentAccountNo,
                excelParentAccountType: $excelParentAccountType,
                excelParentRowNumber: $excelParentRowNumber,
            );
        }

        if ($this->isNominalPnLAccountTypeLabel($accountType)) {
            return new OpeningBalanceNameMatchDTO(
                rowNumber: $rowNumber,
                excelName: $excelName,
                excelCode: $excelCode,
                excelAccountType: $accountType,
                balanceMinor: $balanceMinor,
                status: 'skipped_nominal_pnl',
                decisionStatus: 'skipped',
                action: 'skip',
                skipReason: self::SKIP_REASON_NOMINAL_PNL,
                excelParentName: $excelParentName,
                excelParentAccountNo: $excelParentAccountNo,
                excelParentAccountType: $excelParentAccountType,
                excelParentRowNumber: $excelParentRowNumber,
            );
        }

        if ($balanceMinor === 0) {
            return new OpeningBalanceNameMatchDTO(
                rowNumber: $rowNumber,
                excelName: $excelName,
                excelCode: $excelCode,
                excelAccountType: $accountType,
                balanceMinor: 0,
                status: 'skipped_zero',
                decisionStatus: 'skipped',
                action: 'skip',
                skipReason: 'zero_balance',
                excelParentName: $excelParentName,
                excelParentAccountNo: $excelParentAccountNo,
                excelParentAccountType: $excelParentAccountType,
                excelParentRowNumber: $excelParentRowNumber,
            );
        }

        $normalized = $this->normalizeName($excelName);
        $exactGroup = $byNormalizedName->get($normalized, collect());

        if ($exactGroup->count() === 1) {
            /** @var Account $account */
            $account = $exactGroup->first();

            if ($this->isNominalPnLAccount($account)) {
                return new OpeningBalanceNameMatchDTO(
                    rowNumber: $rowNumber,
                    excelName: $excelName,
                    excelCode: $excelCode,
                    excelAccountType: $accountType,
                    balanceMinor: $balanceMinor,
                    status: 'skipped_nominal_pnl',
                    decisionStatus: 'skipped',
                    action: 'skip',
                    skipReason: self::SKIP_REASON_NOMINAL_PNL,
                    accountId: $account->id,
                    excelParentName: $excelParentName,
                    excelParentAccountNo: $excelParentAccountNo,
                    excelParentAccountType: $excelParentAccountType,
                    excelParentRowNumber: $excelParentRowNumber,
                );
            }

            return new OpeningBalanceNameMatchDTO(
                rowNumber: $rowNumber,
                excelName: $excelName,
                excelCode: $excelCode,
                excelAccountType: $accountType,
                balanceMinor: $balanceMinor,
                status: 'exact',
                decisionStatus: 'suggested',
                action: 'import',
                accountId: $account->id,
                renameToExcel: null,
                excelParentName: $excelParentName,
                excelParentAccountNo: $excelParentAccountNo,
                excelParentAccountType: $excelParentAccountType,
                excelParentRowNumber: $excelParentRowNumber,
            );
        }

        if ($exactGroup->count() > 1) {
            $candidates = $exactGroup->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'similarity' => 100.0,
            ])->values()->all();

            return new OpeningBalanceNameMatchDTO(
                rowNumber: $rowNumber,
                excelName: $excelName,
                excelCode: $excelCode,
                excelAccountType: $accountType,
                balanceMinor: $balanceMinor,
                status: 'ambiguous',
                decisionStatus: 'pending',
                action: null,
                accountId: null,
                renameToExcel: null,
                candidates: $candidates,
                excelParentName: $excelParentName,
                excelParentAccountNo: $excelParentAccountNo,
                excelParentAccountType: $excelParentAccountType,
                excelParentRowNumber: $excelParentRowNumber,
            );
        }

        $candidates = [];
        foreach ($accounts as $account) {
            similar_text($normalized, $this->normalizeName($account->name), $percent);
            if ($percent >= self::FUZZY_THRESHOLD) {
                $candidates[] = [
                    'id' => $account->id,
                    'code' => (string) $account->code,
                    'name' => (string) $account->name,
                    'similarity' => round($percent, 2),
                ];
            }
        }

        usort($candidates, fn (array $a, array $b) => $b['similarity'] <=> $a['similarity']);

        if (count($candidates) === 1) {
            return new OpeningBalanceNameMatchDTO(
                rowNumber: $rowNumber,
                excelName: $excelName,
                excelCode: $excelCode,
                excelAccountType: $accountType,
                balanceMinor: $balanceMinor,
                status: 'fuzzy',
                decisionStatus: 'pending',
                action: null,
                accountId: null,
                renameToExcel: null,
                candidates: $candidates,
                excelParentName: $excelParentName,
                excelParentAccountNo: $excelParentAccountNo,
                excelParentAccountType: $excelParentAccountType,
                excelParentRowNumber: $excelParentRowNumber,
            );
        }

        if (count($candidates) > 1) {
            return new OpeningBalanceNameMatchDTO(
                rowNumber: $rowNumber,
                excelName: $excelName,
                excelCode: $excelCode,
                excelAccountType: $accountType,
                balanceMinor: $balanceMinor,
                status: 'ambiguous',
                decisionStatus: 'pending',
                action: null,
                accountId: null,
                renameToExcel: null,
                candidates: $candidates,
                excelParentName: $excelParentName,
                excelParentAccountNo: $excelParentAccountNo,
                excelParentAccountType: $excelParentAccountType,
                excelParentRowNumber: $excelParentRowNumber,
            );
        }

        $resolution = $this->parentResolution->resolveForNewRow($companyId, $row);
        $suggestedCode = $resolution->suggestedCode;

        if ($resolution->parentAccountId !== null && $reservedCodes->isNotEmpty()) {
            $parent = Account::query()->find($resolution->parentAccountId);
            if ($parent) {
                $suggestedCode = $this->bumpCodeUntilFree($companyId, $parent, $suggestedCode, $reservedCodes);
            }
        } elseif ($reservedCodes->contains($suggestedCode)) {
            $suggestedCode = $this->bumpFlatCodeUntilFree($companyId, $suggestedCode, $reservedCodes);
        }

        return new OpeningBalanceNameMatchDTO(
            rowNumber: $rowNumber,
            excelName: $excelName,
            excelCode: $excelCode,
            excelAccountType: $accountType,
            balanceMinor: $balanceMinor,
            status: 'new',
            decisionStatus: 'pending',
            action: null,
            accountId: null,
            renameToExcel: null,
            suggestedCode: $suggestedCode,
            excelParentName: $resolution->excelParentName,
            excelParentAccountNo: $resolution->excelParentAccountNo,
            excelParentAccountType: $resolution->excelParentAccountType,
            excelParentRowNumber: $resolution->excelParentRowNumber,
            parentAccountId: $resolution->parentAccountId,
            parentAction: $resolution->parentAction,
            parentStatus: $resolution->parentStatus,
            parentSystemCode: $resolution->parentSystemCode,
            parentSystemName: $resolution->parentSystemName,
            codeSource: $resolution->codeSource,
        );
    }

    /**
     * @param  Collection<int, string>  $reservedCodes
     */
    private function bumpCodeUntilFree(int $companyId, Account $parent, string $code, Collection $reservedCodes): string
    {
        $parentCode = (string) $parent->code;
        $separator = str_contains($code, '-') ? '-' : '.';
        $parts = explode($separator, $code);
        $suffix = (int) end($parts);
        $pad = strlen((string) end($parts));

        while (
            $reservedCodes->contains($code)
            || Account::query()->where('company_id', $companyId)->where('code', $code)->exists()
        ) {
            $suffix++;
            $code = $parentCode . $separator . str_pad((string) $suffix, $pad, '0', STR_PAD_LEFT);
        }

        return $code;
    }

    /**
     * @param  Collection<int, string>  $reservedCodes
     */
    private function bumpFlatCodeUntilFree(int $companyId, string $code, Collection $reservedCodes): string
    {
        if (! preg_match('/^(.*?)(\d+)$/', $code, $m)) {
            $n = 1;
            $candidate = $code . '.' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            while ($reservedCodes->contains($candidate) || Account::query()->where('company_id', $companyId)->where('code', $candidate)->exists()) {
                $n++;
                $candidate = $code . '.' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            }

            return $candidate;
        }

        $prefix = $m[1];
        $num = (int) $m[2];
        $pad = strlen($m[2]);
        $candidate = $code;

        while ($reservedCodes->contains($candidate) || Account::query()->where('company_id', $companyId)->where('code', $candidate)->exists()) {
            $num++;
            $candidate = $prefix . str_pad((string) $num, $pad, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    /**
     * @return array{0: JournalEntryType, 1: JournalEntryType}
     */
    public function determineEntryTypes(Account $account, int $diff): array
    {
        $normalDebit = $account->type->isNormalDebitBalance();

        if ($normalDebit) {
            $accountType = $diff > 0 ? JournalEntryType::Debit : JournalEntryType::Credit;
        } else {
            $accountType = $diff > 0 ? JournalEntryType::Credit : JournalEntryType::Debit;
        }

        $equityType = $accountType === JournalEntryType::Debit ? JournalEntryType::Credit : JournalEntryType::Debit;

        return [$accountType, $equityType];
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = str_replace([' ', '-'], '_', $header);

        return match ($header) {
            'account_no', 'account_number', 'akun' => 'account_no',
            'sub_account_no', 'sub_account_number', 'sub_akun' => 'sub_account_no',
            'account_name', 'nama_akun', 'name' => 'account_name',
            'account_type', 'tipe_akun', 'type' => 'account_type',
            'balance', 'saldo', 'saldo_awal' => 'balance',
            default => $header,
        };
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    public function loadMappingFile(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Mapping file not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException("Invalid mapping JSON: {$path}");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    public function writeMappingFile(string $path, array $mapping): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}

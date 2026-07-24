<?php

namespace App\Services;

use App\DTO\OpeningBalanceNameMatchDTO;
use App\Enums\Accounting\AccountType;
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

    /** @deprecated P&L leaf accounts are now imported; kept for legacy mapping JSON display only. */
    public const SKIP_REASON_NOMINAL_PNL_LABEL = 'Legacy: akun pendapatan/biaya (P&L) sebelumnya di-skip; sekarang di-import sebagai saldo awal.';

    public const SKIP_REASON_RETAINED_AFTER_OBE_CLEAR = 'retained_after_obe_clear';

    public const SKIP_REASON_RETAINED_AFTER_OBE_CLEAR_LABEL = 'RETAINED EARNING tidak di-adjust ulang: sudah difinalisasi oleh Clear Opening Balance Equity (menghindari OBE muncul lagi).';

    public const ACTION_ZERO_MISSING = 'zero_missing';

    public const ACTION_CLEAR_OBE = 'clear_obe';

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
            ->with(['parent:id,name,code'])
            ->get(['id', 'code', 'name', 'parent_id', 'type', 'category']);

        $byNormalizedName = collect();
        foreach ($accounts as $account) {
            foreach ($this->normalizedNameLookupKeys((string) $account->name) as $lookupKey) {
                if (! $byNormalizedName->has($lookupKey)) {
                    $byNormalizedName[$lookupKey] = collect();
                }
                $byNormalizedName[$lookupKey]->push($account);
            }
        }

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
            // Hormati skip dari matchRow (parent/computed/ambiguous/unmatched-zero, dll).
            // Exact/fuzzy dengan saldo Excel 0 sekarang action import/map (desired=0), bukan skip.
            if (
                str_starts_with($dto->status, 'skipped')
                || $dto->decisionStatus === 'skipped'
                || $dto->action === 'skip'
            ) {
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
                $c = $dto->candidates[0];
                $summary['map']++;
                $statusLabel = 'Fuzzy → map';
                $destination = "{$c['code']} {$c['name']} (id={$c['id']}, {$c['similarity']}%)";
                $willImport = true;
                $rename = 'tidak';
            } elseif ($dto->status === 'new') {
                $summary['create']++;
                $statusLabel = 'Baru → create';
                $parent = match ($dto->parentAction) {
                    'use_existing' => 'parent existing '.$dto->parentSystemCode.' '.$dto->parentSystemName,
                    'create_parent' => 'parent create '.$dto->excelParentAccountNo.' '.$dto->excelParentName,
                    default => 'tanpa parent',
                };
                $destination = 'create code='.($dto->suggestedCode ?? $dto->excelCode).' type='.$dto->excelAccountType.' ('.$parent.')';
                $willImport = true;
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
        /** @var array<int, true> $touchedAccountIds */
        $touchedAccountIds = [];
        $obeNormalDelta = 0;

        $prefetchIds = [];
        foreach ($ordered as $row) {
            $action = $row['action'] ?? null;
            if (in_array($action, ['import', 'map'], true) && ! empty($row['account_id'])) {
                $prefetchIds[] = (int) $row['account_id'];
            }
        }
        $startingByAccountId = $this->prefetchStartingBalancesMinor($companyId, $year, $prefetchIds);

        /** @var list<array{row: array<string, mixed>, account: ?Account, detail: string, desired_raw: int}> $deferredRetainedApplies */
        $deferredRetainedApplies = [];

        foreach ($ordered as $row) {
            $action = $row['action'] ?? null;
            $name = $row['excel_name'] ?? '';
            $rowNumber = $row['row_number'] ?? '';
            $desired = (int) ($row['balance_minor'] ?? 0);

            if (($row['decision_status'] ?? '') === 'skipped' || $action === 'skip') {
                $reason = $this->formatSkipReason($row['skip_reason'] ?? null);
                $plan[] = [$rowNumber, $name, 'skip', $reason, '-'];

                // Akun yang disebut di Excel (meski di-skip) jangan di-nol-kan sebagai "missing".
                foreach ($this->collectTouchedAccountIdsFromMappingRows([$row]) as $skipAccountId) {
                    $touchedAccountIds[$skipAccountId] = true;
                }

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
                        $code = $this->bumpCodeUntilFree($companyId, $parent, $code, collect());
                        $detail = "create code={$code} type={$type} parent={$parent->code}";
                        $account = $this->accountCreation->createLeafAccountUnderParent(
                            $companyId,
                            $name,
                            $code,
                            $type,
                            $parent,
                        );
                    } else {
                        $code = $this->bumpFlatCodeUntilFree($companyId, $code, collect());
                        $detail = "create code={$code} type={$type}";
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

            // Laba Ditahan / RETAINED EARNING dari Excel: defer sampai setelah zero_missing + clear_obe,
            // supaya angka Excel jadi sumber akhir (bukan tertimpa sisa OBE). Berlaku KPG & KPPB.
            if (
                in_array($action, ['import', 'map', 'create'], true)
                && $this->isRetainedEarningsName($name)
            ) {
                if ($account) {
                    $touchedAccountIds[$account->id] = true;
                }

                $deferredRetainedApplies[] = [
                    'row' => $row,
                    'account' => $account,
                    'detail' => $detail,
                    'desired_raw' => $desired,
                ];

                continue;
            }

            $diffLabel = '-';
            if ($execute && $account) {
                $touchedAccountIds[$account->id] = true;

                $desired = $this->excelSignedToDesiredMinor($account, $desired);
                $currentMinor = $startingByAccountId[$account->id] ?? 0;
                $result = $this->postOpeningBalanceAdjustment(
                    $companyId,
                    $account,
                    $year,
                    $desired,
                    $currentMinor,
                );
                $startingByAccountId[$account->id] = $desired;
                $obeNormalDelta += $this->openingEquityNormalDeltaForAccountDiff($account, $result['diff']);
                $diffLabel = CurrencyConverter::formatCentsToMoney($result['diff']);
                if (! $result['posted']) {
                    $diffLabel .= ' (no post)';
                }
            } elseif ($account) {
                $touchedAccountIds[$account->id] = true;

                $desired = $this->excelSignedToDesiredMinor($account, $desired);
                $currentMinor = $startingByAccountId[$account->id] ?? 0;
                $preview = $this->previewAdjustmentDiff($companyId, $account, $year, $desired, $currentMinor);
                $obeNormalDelta += $this->openingEquityNormalDeltaForAccountDiff($account, $preview['diff']);
                $diffLabel = CurrencyConverter::formatCentsToMoney($preview['diff']);
            } elseif ($action === 'create') {
                $typeLabel = (string) ($row['account_type'] ?? $row['excel_account_type'] ?? '');
                $mappedType = AccountTypeHelper::map($typeLabel, $name);
                if ($mappedType !== null) {
                    $desired = $this->excelSignedToDesiredMinor($mappedType, $desired);
                }
                $diffLabel = CurrencyConverter::formatCentsToMoney($desired) . ' (new)';
            }

            $plan[] = [$rowNumber, $name, $action, $detail, $diffLabel];
        }

        $zeroPlan = $this->zeroAccountsAbsentFromExcel(
            $companyId,
            $year,
            array_keys($touchedAccountIds),
            $execute,
            $obeNormalDelta,
        );

        $preferredRetainedId = $this->preferredRetainedEarningsAccountIdFromMappingRows($ordered);

        // Jika Excel punya Laba Ditahan / RETAINED EARNING: set angka Excel dulu sebagai plug,
        // JANGAN clear_obe ke akun itu (akan menimpa angka Excel dan menyisakan OBE lagi).
        // Sisa OBE (jika ada) di-exclude dari Trial Balance sebagai akun clearing.
        if ($deferredRetainedApplies !== []) {
            $retainedFinalPlan = $this->applyDeferredRetainedEarningsExcelAmounts(
                $deferredRetainedApplies,
                $companyId,
                $year,
                $execute,
                $startingByAccountId,
            );

            $clearPlan = [[
                '—',
                'OPENING BALANCE EQUITY',
                self::ACTION_CLEAR_OBE,
                'dilewati — Excel Laba Ditahan/RE sudah sumber akhir (hindari timpa angka Excel)',
                '-',
            ]];

            return array_merge($plan, $zeroPlan, $retainedFinalPlan, $clearPlan);
        }

        $clearPlan = $this->buildClearOpeningBalanceEquityPlanRows(
            $companyId,
            $year,
            $execute,
            $obeNormalDelta,
            $preferredRetainedId,
        );

        return array_merge($plan, $zeroPlan, $clearPlan);
    }

    /**
     * Terapkan saldo Excel untuk Laba Ditahan / RETAINED EARNING setelah zero_missing + clear_obe.
     *
     * @param  list<array{row: array<string, mixed>, account: ?Account, detail: string, desired_raw: int}>  $deferred
     * @param  array<int, int>  $startingByAccountId
     * @return list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed}>
     */
    public function applyDeferredRetainedEarningsExcelAmounts(
        array $deferred,
        int $companyId,
        int $year,
        bool $execute,
        array &$startingByAccountId = [],
    ): array {
        $plan = [];

        foreach ($deferred as $item) {
            $row = $item['row'];
            $account = $item['account'];
            $detail = $item['detail'].' | final excel (setelah clear OBE)';
            $name = (string) ($row['excel_name'] ?? '');
            $rowNumber = $row['row_number'] ?? '';
            $action = $row['action'] ?? 'import';
            $desiredRaw = (int) $item['desired_raw'];

            if ($account === null && $action === 'create') {
                $typeLabel = (string) ($row['account_type'] ?? $row['excel_account_type'] ?? '');
                $mappedType = AccountTypeHelper::map($typeLabel, $name);
                $desiredPreview = $desiredRaw;
                if ($mappedType !== null) {
                    $desiredPreview = $this->excelSignedToDesiredMinor($mappedType, $desiredRaw);
                }
                $plan[] = [
                    $rowNumber,
                    $name,
                    $action,
                    $detail,
                    CurrencyConverter::formatCentsToMoney($desiredPreview).' (new)',
                ];

                continue;
            }

            if ($account === null) {
                $plan[] = [$rowNumber, $name, $action, $detail.' | account missing', '-'];

                continue;
            }

            $desired = $this->excelSignedToDesiredMinor($account, $desiredRaw);

            if ($execute) {
                // Prefetch bisa stale setelah zero/clear — baca saldo terkini.
                $currentStarting = $this->accountService->getStartingBalance(
                    $account,
                    $this->computeFiscalYearStartForYear(
                        Company::query()->findOrFail($companyId),
                        $year,
                    )->toDateTimeString(),
                    override: true,
                    projectId: null,
                    companyId: $companyId,
                );
                $currentMinor = $currentStarting?->getAmount() ?? 0;
                $result = $this->postOpeningBalanceAdjustment(
                    $companyId,
                    $account,
                    $year,
                    $desired,
                    $currentMinor,
                );
                $startingByAccountId[$account->id] = $desired;
                $diffLabel = CurrencyConverter::formatCentsToMoney($result['diff']);
                if (! $result['posted']) {
                    $diffLabel .= ' (no post)';
                }
            } else {
                $currentMinor = $startingByAccountId[$account->id] ?? 0;
                $preview = $this->previewAdjustmentDiff($companyId, $account, $year, $desired, $currentMinor);
                $diffLabel = CurrencyConverter::formatCentsToMoney($preview['diff']);
            }

            $plan[] = [$rowNumber, $name, $action, $detail, $diffLabel];
        }

        return $plan;
    }

    /**
     * Akun Laba Ditahan / RE dari baris Excel (import/map) — dipakai sebagai tujuan clear OBE.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function preferredRetainedEarningsAccountIdFromMappingRows(array $rows): ?int
    {
        $fallbackId = null;

        foreach ($rows as $row) {
            if (! in_array($row['action'] ?? null, ['import', 'map'], true)) {
                continue;
            }

            $accountId = (int) ($row['account_id'] ?? 0);
            if ($accountId <= 0) {
                continue;
            }

            $excelName = (string) ($row['excel_name'] ?? '');
            if (! $this->isRetainedEarningsName($excelName)) {
                continue;
            }

            $normalized = $this->normalizeName($excelName);
            if (str_contains($normalized, 'laba ditahan')) {
                return $accountId;
            }

            $fallbackId ??= $accountId;
        }

        return $fallbackId;
    }

    /**
     * Perubahan saldo normal-balance Opening Balance Equity saat akun di-adjust sebesar $diff.
     */
    public function openingEquityNormalDeltaForAccountDiff(Account $account, int $diff): int
    {
        if ($diff === 0) {
            return 0;
        }

        [, $obeEntryType] = $this->determineEntryTypes($account, $diff);

        return $obeEntryType === JournalEntryType::Credit ? abs($diff) : -abs($diff);
    }

    /**
     * @return list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed}>
     */
    public function buildClearOpeningBalanceEquityPlanRows(
        int $companyId,
        int $year,
        bool $execute,
        int $obeNormalDeltaFromImport = 0,
        ?int $preferredRetainedEarningsAccountId = null,
    ): array {
        if ($execute) {
            $result = $this->clearOpeningBalanceEquity(
                $companyId,
                $year,
                execute: true,
                retainedEarningsAccountId: $preferredRetainedEarningsAccountId,
            );
        } else {
            $openingEquity = Account::getOpeningBalanceAccount($companyId);
            $company = Company::query()->findOrFail($companyId);
            $fyStart = $this->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
            $current = $this->accountService->getStartingBalance(
                $openingEquity,
                $fyStart,
                override: true,
                projectId: null,
                companyId: $companyId,
            );
            $currentMinor = $current?->getAmount() ?? 0;
            $projectedMinor = $currentMinor + $obeNormalDeltaFromImport;
            $retained = $this->resolveRetainedEarningsAccountForClearing(
                $companyId,
                $preferredRetainedEarningsAccountId,
            );

            $result = [
                'posted' => false,
                'already_clear' => $projectedMinor === 0,
                'diff' => 0 - $projectedMinor,
                'current_minor' => $projectedMinor,
                'retained_earnings_code' => $retained->code,
                'retained_earnings_name' => $retained->name,
            ];
        }

        $detail = $result['already_clear']
            ? 'sudah 0 — tidak ada jurnal'
            : 'ke '.$result['retained_earnings_code'].' '.$result['retained_earnings_name']
                .($execute && ($result['posted'] ?? false) ? ' (posted)' : ' (otomatis setelah import)');

        return [[
            '—',
            'OPENING BALANCE EQUITY',
            self::ACTION_CLEAR_OBE,
            $detail,
            CurrencyConverter::formatCentsToMoney($result['diff']),
        ]];
    }

    /**
     * Account IDs yang disebut di mapping (import/map/skip) — jangan di-nolkan sebagai "missing".
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    public function collectTouchedAccountIdsFromMappingRows(array $rows): array
    {
        $touched = [];

        foreach ($rows as $row) {
            $accountId = (int) ($row['account_id'] ?? 0);
            if ($accountId <= 0) {
                continue;
            }

            $action = $row['action'] ?? null;
            if (
                in_array($action, ['import', 'map', 'skip'], true)
                || ($row['decision_status'] ?? '') === 'skipped'
            ) {
                $touched[$accountId] = true;
            }
        }

        return array_keys($touched);
    }

    /**
     * Nolkan starting balance awal FY untuk akun (neraca dan P&L) yang tidak di-target Excel.
     *
     * Termasuk control/header account yang punya jurnal sendiri (mis. Account Receivable dengan
     * anak kode 1103-*), selama akun itu tidak disentuh mapping Excel. Parent di-skip hanya lewat
     * OBE / Current Year Earnings / nama computed — bukan karena isParentAccount().
     *
     * @param  list<int>  $touchedAccountIds
     * @return list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed}>
     */
    public function zeroAccountsAbsentFromExcel(
        int $companyId,
        int $year,
        array $touchedAccountIds,
        bool $execute,
        int &$obeNormalDelta = 0,
    ): array {
        $touchedLookup = array_fill_keys(array_map('intval', $touchedAccountIds), true);
        // Jangan pakai balanceSheetExcludedAccountIdsForCompany: itu meng-exclude SEMUA parent,
        // padahal control account (parent by code) sering punya saldo JE sendiri yang harus di-nol.
        $excludedLookup = [];

        try {
            $excludedLookup[Account::getOpeningBalanceAccount($companyId)->id] = true;
        } catch (\Throwable) {
            // OBE belum dikonfigurasi — postOpeningBalanceAdjustment akan gagal terpisah jika dipanggil.
        }

        $currentYearEarningsId = Account::getCurrentYearEarningsAccount($companyId)?->id;
        if ($currentYearEarningsId !== null) {
            $excludedLookup[(int) $currentYearEarningsId] = true;
        }

        $plan = [];
        $company = Company::query()->findOrFail($companyId);
        $fyStart = $this->computeFiscalYearStartForYear($company, $year)->toDateTimeString();

        $accounts = Account::query()
            ->where('company_id', $companyId)
            ->whereHas('journalEntries', function ($query) use ($fyStart) {
                $query->whereNull('deleted_at')
                    ->whereHas('transaction', function ($tx) use ($fyStart) {
                        $tx->whereNull('deleted_at')
                            ->where('posted_at', '<', $fyStart);
                    });
            })
            ->orderByRaw('LENGTH(code), code')
            ->get();

        $candidates = $accounts
            ->filter(function (Account $account) use ($touchedLookup, $excludedLookup) {
                if (isset($touchedLookup[$account->id]) || isset($excludedLookup[$account->id])) {
                    return false;
                }

                // Jangan nolkan Laba Ditahan / RETAINED EARNING lewat OBE — angka Excel di-set
                // di tahap final; menolkan orphan RE hanya memindahkan selisih ke OBE.
                if ($this->isRetainedEarningsName($account->name)) {
                    return false;
                }

                return ! $this->isComputedCurrentYearEarningsName($account->name);
            })
            ->values();

        $startingByAccountId = $this->prefetchStartingBalancesMinor(
            $companyId,
            $year,
            $candidates->pluck('id')->all(),
        );

        foreach ($candidates as $account) {
            $currentMinor = $startingByAccountId[$account->id] ?? 0;

            if ($execute) {
                $result = $this->postOpeningBalanceAdjustment(
                    $companyId,
                    $account,
                    $year,
                    0,
                    $currentMinor,
                );
                if ($result['diff'] === 0) {
                    continue;
                }

                $obeNormalDelta += $this->openingEquityNormalDeltaForAccountDiff($account, $result['diff']);
                $diffLabel = CurrencyConverter::formatCentsToMoney($result['diff']);
                if (! $result['posted']) {
                    $diffLabel .= ' (no post)';
                }
            } else {
                $preview = $this->previewAdjustmentDiff($companyId, $account, $year, 0, $currentMinor);
                if ($preview['diff'] === 0) {
                    continue;
                }

                $obeNormalDelta += $this->openingEquityNormalDeltaForAccountDiff($account, $preview['diff']);
                $diffLabel = CurrencyConverter::formatCentsToMoney($preview['diff']);
            }

            $plan[] = [
                '—',
                $account->name,
                self::ACTION_ZERO_MISSING,
                "id={$account->id} {$account->code}",
                $diffLabel,
            ];
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
            // Notasi akuntansi (1.234,56) = negatif / sisi credit di template KPG.
            $negativeByParens = false;
            if (preg_match('/^\((.*)\)$/', $raw, $matches) === 1) {
                $negativeByParens = true;
                $raw = $matches[1];
            }
            // Normalisasi minus unicode / dash agar "-652.395.427,84" tetap ter-parse.
            $raw = preg_replace('/^[−–—‐]/u', '-', $raw) ?? $raw;
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
            $value = (float) $raw;
            if ($negativeByParens) {
                $value = -abs($value);
            }

            return CurrencyConverter::convertToCents($value, $currency);
        }

        return CurrencyConverter::convertToCents((float) $raw, $currency);
    }

    /**
     * Konversi saldo Excel KPG ke desiredMinor sistem (normal-balance signed).
     *
     * Tanda minus / kurung di Excel diabaikan — hanya magnitude yang dipakai.
     * Sisi debit/kredit ditentukan oleh normal balance tipe akun
     * ({@see AccountType::isNormalDebitBalance()} + {@see self::determineEntryTypes()}):
     * desiredMinor selalu ≥ 0 (naikkan saldo di sisi normal akun).
     */
    public function excelSignedToDesiredMinor(Account | AccountType $accountOrType, int $excelSignedMinor): int
    {
        return abs($excelSignedMinor);
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
        ?int $currentMinor = null,
    ): array {
        // Control/header accounts may carry their own JE balance (invoice AR control, etc.).
        // Skip only when desired comes from an Excel parent row — callers already filter those.

        $company = Company::query()->findOrFail($companyId);
        $fyStart = $this->computeFiscalYearStartForYear($company, $year);
        $postedAt = $fyStart->copy()->subSecond()->toDateTimeString();
        $startDateTime = $fyStart->toDateTimeString();

        if ($currentMinor === null) {
            $currentStarting = $this->accountService->getStartingBalance(
                $account,
                $startDateTime,
                override: true,
                projectId: null,
                companyId: $companyId,
            );
            $currentMinor = $currentStarting?->getAmount() ?? 0;
        }

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
     * Nolkan saldo Opening Balance Equity ke Retained Earnings agar saldo awal FY bersih.
     * Jurnal diposting tepat sebelum FY start (sama seperti import adjust), supaya starting balance FY = 0.
     *
     * @return array{
     *     posted: bool,
     *     already_clear: bool,
     *     diff: int,
     *     current_minor: int,
     *     opening_equity_id: int,
     *     retained_earnings_id: int,
     *     retained_earnings_code: string,
     *     retained_earnings_name: string,
     *     transaction_id: ?int,
     *     posted_at: string
     * }
     */
    public function clearOpeningBalanceEquity(
        int $companyId,
        int $year,
        bool $execute = false,
        ?int $retainedEarningsAccountId = null,
    ): array {
        $company = Company::query()->findOrFail($companyId);
        $fyStart = $this->computeFiscalYearStartForYear($company, $year);
        $postedAt = $fyStart->copy()->subSecond()->toDateTimeString();
        $startDateTime = $fyStart->toDateTimeString();

        $openingEquity = Account::getOpeningBalanceAccount($companyId);
        $retainedEarnings = $this->resolveRetainedEarningsAccountForClearing($companyId, $retainedEarningsAccountId);

        $currentStarting = $this->accountService->getStartingBalance(
            $openingEquity,
            $startDateTime,
            override: true,
            projectId: null,
            companyId: $companyId,
        );
        $currentMinor = $currentStarting?->getAmount() ?? 0;
        $diff = 0 - $currentMinor;

        $base = [
            'posted' => false,
            'already_clear' => $diff === 0,
            'diff' => $diff,
            'current_minor' => $currentMinor,
            'opening_equity_id' => $openingEquity->id,
            'retained_earnings_id' => $retainedEarnings->id,
            'retained_earnings_code' => $retainedEarnings->code,
            'retained_earnings_name' => $retainedEarnings->name,
            'transaction_id' => null,
            'posted_at' => $postedAt,
        ];

        if ($diff === 0 || ! $execute) {
            return $base;
        }

        [$obeEntryType, $reEntryType] = $this->determineEntryTypes($openingEquity, $diff);
        $amount = abs($diff);
        $transactionId = null;

        DB::transaction(function () use (
            $companyId,
            $openingEquity,
            $retainedEarnings,
            $postedAt,
            $amount,
            $obeEntryType,
            $reEntryType,
            $year,
            &$transactionId,
        ) {
            $transaction = Transaction::create([
                'company_id' => $companyId,
                'bank_account_id' => null,
                'type' => 'journal',
                'description' => "Clear Opening Balance Equity FY-{$year}",
                'posted_at' => $postedAt,
                'amount' => $amount,
                'reviewed' => true,
                'meta' => [
                    'addon' => 'opening_balance_equity_clear',
                    'year' => $year,
                    'opening_equity_code' => $openingEquity->code,
                    'retained_earnings_code' => $retainedEarnings->code,
                ],
            ]);

            $transactionId = $transaction->id;

            JournalEntry::create([
                'company_id' => $companyId,
                'transaction_id' => $transaction->id,
                'account_id' => $openingEquity->id,
                'type' => $obeEntryType,
                'amount' => $amount,
                'description' => "Clear OBE FY-{$year} — {$openingEquity->code}",
            ]);

            JournalEntry::create([
                'company_id' => $companyId,
                'transaction_id' => $transaction->id,
                'account_id' => $retainedEarnings->id,
                'type' => $reEntryType,
                'amount' => $amount,
                'description' => "Clear OBE FY-{$year} — ke {$retainedEarnings->code}",
            ]);
        });

        return array_merge($base, [
            'posted' => true,
            'transaction_id' => $transactionId,
        ]);
    }

    public function resolveRetainedEarningsAccountForClearing(int $companyId, ?int $accountId = null): Account
    {
        if ($accountId !== null) {
            $account = Account::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereKey($accountId)
                ->first();

            if (! $account) {
                throw new InvalidArgumentException("Akun retained earnings #{$accountId} tidak ditemukan untuk company {$companyId}.");
            }

            return $account;
        }

        $account = Account::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where(function ($query): void {
                $query->whereRaw('LOWER(name) LIKE ?', ['%retained earn%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%laba ditahan%']);
            })
            // Prefer "Laba Ditahan" (nama Excel KPG/KPPB) over legacy "RETAINED EARNING"
            // agar clear OBE tidak menumpuk lagi ke akun orphan yang tidak ada di Excel.
            ->orderByRaw("CASE
                WHEN LOWER(name) LIKE '%laba ditahan%' THEN 0
                WHEN LOWER(name) LIKE '%retained earn%' THEN 1
                ELSE 2
            END")
            ->orderByRaw('LENGTH(code), code')
            ->first();

        if (! $account) {
            $account = Account::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('code', 'like', '3200%')
                ->whereRaw('LOWER(name) NOT LIKE ?', ['%deviden%'])
                ->whereRaw('LOWER(name) NOT LIKE ?', ['%dividend%'])
                ->orderByRaw('LENGTH(code), code')
                ->first();
        }

        if (! $account) {
            throw new InvalidArgumentException(
                "Akun Retained Earnings (nama retained earn / laba ditahan) belum ada untuk company {$companyId}."
            );
        }

        return $account;
    }

    /**
     * Prefetch starting balances (minor units) for many accounts in one query.
     *
     * @param  list<int>  $accountIds
     * @return array<int, int>
     */
    public function prefetchStartingBalancesMinor(int $companyId, int $year, array $accountIds): array
    {
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if ($accountIds === []) {
            return [];
        }

        $company = Company::query()->findOrFail($companyId);
        $fyStart = $this->computeFiscalYearStartForYear($company, $year)->toDateTimeString();

        return $this->accountService
            ->getAccountBalances($fyStart, $fyStart, $accountIds, null, $companyId)
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->id => (int) ($row->starting_balance ?? 0)])
            ->all();
    }

    public function previewAdjustmentDiff(
        int $companyId,
        Account $account,
        int $year,
        int $desiredMinor,
        ?int $currentMinor = null,
    ): array {
        if ($currentMinor === null) {
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
        }

        return [
            'diff' => $desiredMinor - $currentMinor,
            'current_minor' => $currentMinor,
            'desired_minor' => $desiredMinor,
        ];
    }

    public function normalizeName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = str_replace(['.', ',', '(', ')', '-', '/', '&', '_'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        // Jangan samakan "beban" ↔ "biaya" di sini: di KPPE ada "Biaya Pajak" dan "Beban Pajak"
        // sebagai akun berbeda; sinonim itu hanya untuk match PARENT di AccountParentResolutionService.

        return trim($normalized);
    }

    public function formatSkipReason(?string $reason): string
    {
        return match ($reason) {
            self::SKIP_REASON_COMPUTED_CURRENT_YEAR_EARNINGS => self::SKIP_REASON_COMPUTED_CURRENT_YEAR_EARNINGS_LABEL,
            self::SKIP_REASON_NOMINAL_PNL => self::SKIP_REASON_NOMINAL_PNL_LABEL,
            self::SKIP_REASON_RETAINED_AFTER_OBE_CLEAR => self::SKIP_REASON_RETAINED_AFTER_OBE_CLEAR_LABEL,
            'parent_row' => 'Baris parent (bukan leaf)',
            'zero_balance' => 'Saldo 0',
            'ambiguous_or_pending' => 'Ambiguous / perlu decide manual',
            default => $reason ?? '-',
        };
    }

    /**
     * Alias nama Excel ↔ sistem (bentuk sebelum/sesudah normalize).
     *
     * @return list<list<string>>
     */
    public function nameAliasGroups(): array
    {
        return [
            ['piutang pt kppe', 'piutang pt. kppe'],
            ['pph final pasal 4 2', 'pph pasal 4 2'],
            ['hutang denda bunga obligasi', 'hutang bunga obligasi'],
            ['biaya obligasi', 'biaya bunga obligasi'],
            ['biaya pajak jasa giro', 'pajak jasa giro'],
            ['pph pasal 28 tahun 2024', 'pph pasal 28 lebih bayar'],
        ];
    }

    /**
     * @return list<string>
     */
    public function normalizedNameLookupKeys(string $name): array
    {
        $normalized = $this->normalizeName($name);
        $keys = [$normalized];

        // Varian tanpa frasa opsional "tenaga kerja" (Excel: Pendapatan Tenaga Kerja SDM ↔ app: Pendapatan SDM).
        $withoutTenagaKerja = trim(preg_replace(
            '/\s+/',
            ' ',
            preg_replace('/\btenaga kerja\b/u', ' ', $normalized) ?? $normalized,
        ) ?? $normalized);
        if ($withoutTenagaKerja !== '' && $withoutTenagaKerja !== $normalized) {
            $keys[] = $withoutTenagaKerja;
        }

        foreach ($this->nameAliasGroups() as $group) {
            $groupNormalized = array_values(array_unique(array_map(
                fn (string $alias): string => $this->normalizeName($alias),
                $group,
            )));

            foreach ($keys as $key) {
                if (in_array($key, $groupNormalized, true)) {
                    foreach ($groupNormalized as $alias) {
                        $keys[] = $alias;
                    }
                    break;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    public function isRetainedEarningsName(string $name): bool
    {
        $normalized = $this->normalizeName($name);

        return in_array($normalized, [
            'retained earning',
            'retained earnings',
            'laba ditahan',
        ], true)
            || str_contains($normalized, 'retained earn')
            || str_contains($normalized, 'laba ditahan');
    }

    public function hasOpeningBalanceEquityClearForYear(int $companyId, int $year): bool
    {
        return Transaction::query()
            ->where('company_id', $companyId)
            ->where('meta->addon', 'opening_balance_equity_clear')
            ->where('meta->year', $year)
            ->exists();
    }

    /**
     * Tambah kaki lawan ke Opening Balance Equity untuk setiap transaksi yang D≠C
     * (hingga $asOfInclusive), agar Trial Balance bisa balance tanpa mengubah saldo akun lain.
     *
     * @return array{
     *     scanned: int,
     *     fixed: int,
     *     skipped: int,
     *     net_debit_excess: int,
     *     details: list<array{transaction_id: int, diff: int, entry_type: string, amount: int}>
     * }
     */
    public function balanceUnbalancedJournalsToOpeningEquity(
        int $companyId,
        string $asOfInclusive,
        bool $execute = false,
    ): array {
        $openingEquity = Account::getOpeningBalanceAccount($companyId);
        $asOf = Carbon::parse($asOfInclusive)->endOfDay()->toDateTimeString();

        $unbalanced = DB::table('transactions as t')
            ->join('journal_entries as je', function ($join) {
                $join->on('je.transaction_id', '=', 't.id')
                    ->whereNull('je.deleted_at');
            })
            ->where('t.company_id', $companyId)
            ->whereNull('t.deleted_at')
            ->where('t.posted_at', '<=', $asOf)
            ->groupBy('t.id')
            ->havingRaw("SUM(CASE WHEN je.type = 'debit' THEN je.amount ELSE 0 END) <> SUM(CASE WHEN je.type = 'credit' THEN je.amount ELSE 0 END)")
            ->selectRaw("
                t.id as transaction_id,
                SUM(CASE WHEN je.type = 'debit' THEN je.amount ELSE 0 END) as total_debit,
                SUM(CASE WHEN je.type = 'credit' THEN je.amount ELSE 0 END) as total_credit
            ")
            ->orderBy('t.id')
            ->get();

        $details = [];
        $fixed = 0;
        $skipped = 0;
        $netDebitExcess = 0;

        foreach ($unbalanced as $row) {
            $diff = (int) $row->total_debit - (int) $row->total_credit;
            if ($diff === 0) {
                $skipped++;

                continue;
            }

            // Excess debit → butuh credit; excess credit → butuh debit.
            $entryType = $diff > 0 ? JournalEntryType::Credit : JournalEntryType::Debit;
            $amount = abs($diff);
            $netDebitExcess += $diff;

            $details[] = [
                'transaction_id' => (int) $row->transaction_id,
                'diff' => $diff,
                'entry_type' => $entryType->value,
                'amount' => $amount,
            ];

            if (! $execute) {
                $fixed++;

                continue;
            }

            JournalEntry::create([
                'company_id' => $companyId,
                'transaction_id' => (int) $row->transaction_id,
                'account_id' => $openingEquity->id,
                'type' => $entryType,
                'amount' => $amount,
                'description' => 'Balance plug to Opening Balance Equity (TB D/C fix)',
            ]);

            $fixed++;
        }

        return [
            'scanned' => $unbalanced->count(),
            'fixed' => $fixed,
            'skipped' => $skipped,
            'net_debit_excess' => $netDebitExcess,
            'details' => $details,
        ];
    }

    /**
     * Soft-delete clear OBE dobel + pasangan Import Adjust yang membentuk loop re-import,
     * lalu clear sisa OBE (jika ada).
     *
     * @return array{
     *     deleted_transaction_ids: list<int>,
     *     kept_clear_transaction_id: ?int,
     *     residual_clear: array<string, mixed>
     * }
     */
    public function dedupeOpeningBalanceEquityClears(int $companyId, int $year, bool $execute = false): array
    {
        $clears = Transaction::query()
            ->where('company_id', $companyId)
            ->where('meta->addon', 'opening_balance_equity_clear')
            ->where('meta->year', $year)
            ->orderBy('id')
            ->get();

        $keptClearId = $clears->first()?->id;
        $deletedIds = [];

        foreach ($clears->slice(1)->values() as $clearTxn) {
            $amount = (int) $clearTxn->amount;
            $pair = null;
            if ($keptClearId !== null) {
                $pair = Transaction::query()
                    ->where('company_id', $companyId)
                    ->where('meta->addon', 'opening_balance_import')
                    ->where('meta->year', $year)
                    ->where('amount', $amount)
                    ->where('id', '>', $keptClearId)
                    ->where('id', '<', $clearTxn->id)
                    ->orderByDesc('id')
                    ->first();
            }

            if ($execute) {
                $clearTxn->delete();
                $pair?->delete();
            }

            $deletedIds[] = (int) $clearTxn->id;
            if ($pair) {
                $deletedIds[] = (int) $pair->id;
            }
        }

        $residualClear = $this->clearOpeningBalanceEquity($companyId, $year, execute: $execute);

        return [
            'deleted_transaction_ids' => array_values(array_unique($deletedIds)),
            'kept_clear_transaction_id' => $keptClearId !== null ? (int) $keptClearId : null,
            'residual_clear' => $residualClear,
        ];
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

        // Nama child Excel sering dipakai ulang di beberapa parent (mis. KPPE:
        // "Piutang Pemegang Saham" di bawah AR vs "Aset Lain-lain"). Prefer akun
        // yang nama-nya memuat konteks parent + nama child, daripada exact bare name.
        $parentQualified = $this->parentQualifiedAccounts($excelName, $excelParentName, $accounts);
        if ($parentQualified->count() > 1) {
            $childKeys = $this->normalizedNameLookupKeys($excelName);
            $exactUnderParent = $parentQualified
                ->filter(fn (Account $account) => in_array(
                    $this->normalizeName((string) $account->name),
                    $childKeys,
                    true,
                ))
                ->values();
            if ($exactUnderParent->count() === 1) {
                $parentQualified = $exactUnderParent;
            }
        }
        // Leaf Excel non-zero tidak boleh ke akun parent/header (TB exclude parent → saldo hilang).
        // Saldo 0 tetap boleh match parent agar KPG/KPPB (mis. 8003=0 → header) tidak berubah.
        $parentQualified = $this->rejectStructuralParentsForNonZeroLeaf(
            $parentQualified,
            $balanceMinor,
            $accounts,
        );
        if ($parentQualified->count() === 1) {
            /** @var Account $account */
            $account = $parentQualified->first();

            // Saldo Excel 0 tetap di-import (desired=0) agar sisa OB salah ikut ter-clear.
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

        if ($parentQualified->count() > 1) {
            $candidates = $parentQualified->map(fn (Account $account) => [
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
                decisionStatus: $balanceMinor === 0 ? 'skipped' : 'pending',
                action: $balanceMinor === 0 ? 'skip' : null,
                accountId: null,
                renameToExcel: null,
                candidates: $candidates,
                skipReason: $balanceMinor === 0 ? 'zero_balance' : null,
                excelParentName: $excelParentName,
                excelParentAccountNo: $excelParentAccountNo,
                excelParentAccountType: $excelParentAccountType,
                excelParentRowNumber: $excelParentRowNumber,
            );
        }

        $exactGroup = collect();
        foreach ($this->normalizedNameLookupKeys($excelName) as $lookupKey) {
            $exactGroup = $exactGroup->merge($byNormalizedName->get($lookupKey, collect()));
        }
        $exactGroup = $exactGroup->unique('id')->values();

        if ($exactGroup->count() > 1) {
            $exactGroup = $this->disambiguateAccounts(
                $exactGroup,
                $accountType,
                $excelName,
                $excelParentName,
            );
        }

        // Prefer literal name equality sebelum alias/synonym luas (cegah Biaya Pajak ↔ Beban Pajak).
        if ($exactGroup->count() > 1) {
            $literal = $this->normalizeName($excelName);
            $literalMatches = $exactGroup
                ->filter(fn (Account $account) => $this->normalizeName((string) $account->name) === $literal)
                ->values();
            if ($literalMatches->count() === 1) {
                $exactGroup = $literalMatches;
            }
        }

        $exactGroup = $this->rejectStructuralParentsForNonZeroLeaf(
            $exactGroup,
            $balanceMinor,
            $accounts,
        );

        if ($exactGroup->count() === 1) {
            /** @var Account $account */
            $account = $exactGroup->first();

            // Saldo Excel 0 tetap di-import (desired=0) agar sisa OB salah ikut ter-clear.
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
                decisionStatus: $balanceMinor === 0 ? 'skipped' : 'pending',
                action: $balanceMinor === 0 ? 'skip' : null,
                accountId: null,
                renameToExcel: null,
                candidates: $candidates,
                skipReason: $balanceMinor === 0 ? 'zero_balance' : null,
                excelParentName: $excelParentName,
                excelParentAccountNo: $excelParentAccountNo,
                excelParentAccountType: $excelParentAccountType,
                excelParentRowNumber: $excelParentRowNumber,
            );
        }

        $candidates = $this->fuzzyCandidatesForName($excelName, $accounts, $accountType);

        if (count($candidates) > 1) {
            $candidateAccounts = $accounts
                ->whereIn('id', array_column($candidates, 'id'))
                ->values();
            $resolved = $this->disambiguateAccounts(
                $candidateAccounts,
                $accountType,
                $excelName,
                $excelParentName,
            );
            if ($resolved->count() === 1) {
                $winnerId = (int) $resolved->first()->id;
                $candidates = array_values(array_filter(
                    $candidates,
                    fn (array $c): bool => (int) $c['id'] === $winnerId,
                ));
            }
        }

        if ($balanceMinor !== 0 && $candidates !== []) {
            $parentIds = Account::parentAccountIdsFromCollection($accounts)->flip();
            $candidates = array_values(array_filter(
                $candidates,
                fn (array $c): bool => ! $parentIds->has((int) $c['id']),
            ));
        }

        if (count($candidates) === 1) {
            // Saldo Excel 0 tetap di-map (desired=0) agar sisa OB salah ikut ter-clear.
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
                decisionStatus: $balanceMinor === 0 ? 'skipped' : 'pending',
                action: $balanceMinor === 0 ? 'skip' : null,
                accountId: null,
                renameToExcel: null,
                candidates: $candidates,
                skipReason: $balanceMinor === 0 ? 'zero_balance' : null,
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

        $resolution = $this->parentResolution->resolveForNewRow($companyId, $row);
        $suggestedCode = $resolution->suggestedCode;

        // Selalu pastikan kode usulan bebas di DB + reserved (termasuk create_parent → *.01).
        if ($resolution->parentAccountId !== null) {
            $parent = Account::query()->find($resolution->parentAccountId);
            if ($parent) {
                $suggestedCode = $this->bumpCodeUntilFree($companyId, $parent, $suggestedCode, $reservedCodes);
            } else {
                $suggestedCode = $this->bumpFlatCodeUntilFree($companyId, $suggestedCode, $reservedCodes);
            }
        } else {
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
     * Token parent Excel yang membedakan konteks (tidak muncul di nama child).
     *
     * @return list<string>
     */
    public function parentContextTokens(string $parentName, string $childName): array
    {
        $stopwords = [
            'dan', 'di', 'ke', 'dari', 'yang', 'the', 'of', 'a', 'an', 'untuk', 'pada', 'dalam', 'atau',
        ];
        $childTokens = array_values(array_filter(
            explode(' ', $this->normalizeName($childName)),
            fn (string $token): bool => $token !== '',
        ));
        $parentTokens = array_values(array_unique(array_filter(
            explode(' ', $this->normalizeName($parentName)),
            fn (string $token): bool => $token !== '',
        )));

        return array_values(array_filter(
            $parentTokens,
            function (string $token) use ($stopwords, $childTokens): bool {
                if (mb_strlen($token) < 3) {
                    return false;
                }
                if (in_array($token, $stopwords, true)) {
                    return false;
                }

                return ! in_array($token, $childTokens, true);
            },
        ));
    }

    /**
     * Leaf Excel dengan saldo ≠ 0 tidak boleh di-post ke akun parent/header —
     * Trial Balance mengecualikan parent, sehingga saldo "hilang" dari total
     * (kasus KPPE: Excel 8003 Beban Lain-lain → header 7100-004).
     *
     * Saldo 0 tetap boleh match parent agar perilaku KPG/KPPB tidak berubah
     * (Excel 8003 = 0 ke header Beban Lain-Lain).
     *
     * @param  Collection<int, Account>  $candidates
     * @param  Collection<int, Account>  $accounts
     * @return Collection<int, Account>
     */
    private function rejectStructuralParentsForNonZeroLeaf(
        Collection $candidates,
        int $balanceMinor,
        Collection $accounts,
    ): Collection {
        if ($balanceMinor === 0 || $candidates->isEmpty()) {
            return $candidates;
        }

        $parentIds = Account::parentAccountIdsFromCollection($accounts)->flip();

        return $candidates
            ->reject(fn (Account $account) => $parentIds->has((int) $account->id))
            ->values();
    }

    /**
     * Akun yang cocok dengan konteks parent Excel:
     * 1) nama akun memuat token parent + nama child (bukan bare exact), atau
     * 2) parent relation akun = parent Excel dan nama child match/terkandung.
     *
     * Contoh KPPE: "PPh 21" di bawah "Biaya Yang Masih Harus Dibayar"
     * → "PPh 21 yang masih harus dibayar" (bukan "PPh 21" di bawah Piutang Pajak).
     *
     * @param  Collection<int, Account>  $accounts
     * @return Collection<int, Account>
     */
    public function parentQualifiedAccounts(
        string $excelName,
        ?string $excelParentName,
        Collection $accounts,
    ): Collection {
        if ($excelParentName === null || trim($excelParentName) === '') {
            return collect();
        }

        $childKey = $this->normalizeName($excelName);
        $parentKey = $this->normalizeName($excelParentName);
        $contextTokens = $this->parentContextTokens($excelParentName, $excelName);
        $childLookupKeys = $this->normalizedNameLookupKeys($excelName);

        if ($childKey === '') {
            return collect();
        }

        return $accounts
            ->filter(function (Account $account) use ($childKey, $parentKey, $contextTokens, $childLookupKeys): bool {
                $accountKey = $this->normalizeName((string) $account->name);
                if ($accountKey === '') {
                    return false;
                }

                $parentName = $account->parent?->name;
                $parentMatches = $parentName !== null
                    && $parentName !== ''
                    && $this->normalizeName($parentName) === $parentKey;

                if ($parentMatches) {
                    foreach ($childLookupKeys as $lookupKey) {
                        if ($accountKey === $lookupKey) {
                            return true;
                        }
                    }

                    // Child terkandung di nama akun (mis. "PPh 21" → "PPh 21 yang masih harus dibayar").
                    // Jangan pakai similar_text di sini: "Biaya ..." vs "BPJS ..." sering >= threshold
                    // karena berbagi suffix "yang masih harus dibayar", lalu jadi ambiguous.
                    if (str_contains($accountKey, $childKey)) {
                        return true;
                    }

                    return false;
                }

                // Fallback: nama akun memuat child + token konteks parent (tanpa parent relation).
                if ($contextTokens === [] || $accountKey === $childKey) {
                    return false;
                }
                if (! str_contains($accountKey, $childKey)) {
                    return false;
                }
                foreach ($contextTokens as $token) {
                    if (! str_contains($accountKey, $token)) {
                        return false;
                    }
                }

                return true;
            })
            ->unique('id')
            ->values();
    }

    /**
     * Pecah kandidat nama kembar pakai tipe Excel, lalu parent Excel jika masih >1.
     *
     * @param  Collection<int, Account>  $accounts
     * @return Collection<int, Account>
     */
    public function disambiguateAccounts(
        Collection $accounts,
        ?string $excelAccountType,
        ?string $excelName = null,
        ?string $excelParentName = null,
    ): Collection {
        if ($accounts->count() <= 1) {
            return $accounts->values();
        }

        $mappedType = $excelAccountType
            ? AccountTypeHelper::map($excelAccountType, $excelName)
            : null;

        if ($mappedType !== null) {
            $byType = $accounts
                ->filter(fn (Account $account) => $account->type === $mappedType)
                ->values();

            if ($byType->count() === 1) {
                return $byType;
            }

            if ($byType->isNotEmpty()) {
                $accounts = $byType;
            }
        }

        if ($excelParentName !== null && trim($excelParentName) !== '' && $accounts->count() > 1) {
            $parentKey = $this->normalizeName($excelParentName);
            $byParent = $accounts
                ->filter(function (Account $account) use ($parentKey) {
                    $parentName = $account->parent?->name;
                    if ($parentName === null || $parentName === '') {
                        return false;
                    }

                    return $this->normalizeName($parentName) === $parentKey;
                })
                ->values();

            if ($byParent->count() === 1) {
                return $byParent;
            }

            if ($byParent->isNotEmpty()) {
                return $byParent;
            }
        }

        return $accounts->values();
    }

    /**
     * Angka di nama Excel harus muncul di nama akun (cegah PPh 29 ↔ PPh 23, tahun 2021 ↔ 2024, dll).
     */
    public function fuzzyNumberTokensCompatible(string $excelName, string $accountName): bool
    {
        $excelNumbers = $this->extractNameNumberTokens($excelName);
        if ($excelNumbers === []) {
            return true;
        }

        $accountNumbers = $this->extractNameNumberTokens($accountName);
        foreach ($excelNumbers as $number) {
            if (! in_array($number, $accountNumbers, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function extractNameNumberTokens(string $name): array
    {
        preg_match_all('/\d+/', $this->normalizeName($name), $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /**
     * Token pembeda: akun cocok jika token pembeda akun ⊆ excel, atau sebaliknya.
     * Mengizinkan Excel lebih panjang ("… Peralatan kantor" → "… Peralatan") tanpa
     * membuka SDM↔SCM (token pembeda berbeda).
     */
    public function fuzzyDiscriminatingTokensCompatible(string $excelName, string $accountName): bool
    {
        $excelTokens = $this->discriminatingNameTokens($excelName);
        $accountTokens = $this->discriminatingNameTokens($accountName);

        if ($excelTokens === [] || $accountTokens === []) {
            return true;
        }

        $excelKey = $this->normalizeName($excelName);
        $accountKey = $this->normalizeName($accountName);

        $accountAllInExcel = true;
        foreach ($accountTokens as $token) {
            if (! str_contains($excelKey, $token)) {
                $accountAllInExcel = false;
                break;
            }
        }

        $excelAllInAccount = true;
        foreach ($excelTokens as $token) {
            if (! str_contains($accountKey, $token)) {
                $excelAllInAccount = false;
                break;
            }
        }

        return $accountAllInExcel || $excelAllInAccount;
    }

    /**
     * @return list<string>
     */
    public function discriminatingNameTokens(string $name): array
    {
        $fillers = [
            'pendapatan', 'piutang', 'biaya', 'beban', 'hpp', 'tk', 'tenaga', 'kerja',
            'utang', 'hutang', 'yang', 'masih', 'harus', 'dibayar', 'lain', 'dan',
            'di', 'ke', 'dari', 'untuk', 'pada', 'dalam', 'atau', 'the', 'of', 'a', 'an',
            'account', 'receivable', 'payable',
        ];

        return array_values(array_filter(
            explode(' ', $this->normalizeName($name)),
            fn (string $token): bool => $token !== ''
                && mb_strlen($token) >= 2
                && ! in_array($token, $fillers, true),
        ));
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return list<array{id: int, code: string, name: string, similarity: float}>
     */
    private function fuzzyCandidatesForName(
        string $excelName,
        Collection $accounts,
        ?string $excelAccountType = null,
    ): array {
        $lookupKeys = $this->normalizedNameLookupKeys($excelName);
        $mappedType = $excelAccountType
            ? AccountTypeHelper::map($excelAccountType, $excelName)
            : null;
        $candidates = [];

        foreach ($accounts as $account) {
            if ($mappedType !== null && $account->type !== $mappedType) {
                // Izinkan beda label tipe yang map ke kategori sama (AR/OCA → current_asset).
                $accountCategory = $account->category ?? $account->type?->getCategory();
                if ($accountCategory === null || $accountCategory !== $mappedType->getCategory()) {
                    continue;
                }
            }

            if (! $this->fuzzyNumberTokensCompatible($excelName, (string) $account->name)) {
                continue;
            }

            if (! $this->fuzzyDiscriminatingTokensCompatible($excelName, (string) $account->name)) {
                continue;
            }

            $accountKeys = $this->normalizedNameLookupKeys($account->name);
            $best = 0.0;
            foreach ($lookupKeys as $excelKey) {
                foreach ($accountKeys as $accountKey) {
                    similar_text($excelKey, $accountKey, $percent);
                    if ($percent > $best) {
                        $best = $percent;
                    }
                }
            }

            if ($best >= self::FUZZY_THRESHOLD) {
                $candidates[] = [
                    'id' => $account->id,
                    'code' => (string) $account->code,
                    'name' => (string) $account->name,
                    'similarity' => round($best, 2),
                ];
            }
        }

        usort($candidates, fn (array $a, array $b) => $b['similarity'] <=> $a['similarity']);

        return $candidates;
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

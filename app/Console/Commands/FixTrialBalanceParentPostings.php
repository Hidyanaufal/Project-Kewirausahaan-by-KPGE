<?php

namespace App\Console\Commands;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountDefaultKey;
use App\Models\AccountDefault;
use App\Models\Accounting\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Common\Offering;
use App\Models\Company;
use App\Scopes\CurrentCompanyScope;
use App\Services\AccountService;
use App\Services\OpeningBalanceNameImportService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data-repair only: remap postings from parent/header accounts (hidden on TB) to leaf accounts.
 * Does not change Invoice/Bill posting business logic.
 */
class FixTrialBalanceParentPostings extends Command
{
    protected $signature = 'accounting:fix-trial-balance-parent-postings
                            {--company-id= : Company ID (wajib kecuali --all-companies)}
                            {--all-companies : Jalankan untuk semua company}
                            {--as-of= : Tanggal as-of untuk verifikasi TB (Y-m-d), default: hari ini}
                            {--plug-unbalanced : Setelah remap, plug sisa jurnal D≠C ke Opening Balance Equity}
                            {--dry-run : Preview tanpa menulis ke DB}
                            {--execute : Terapkan perubahan}';

    protected $description = 'Data repair: pindahkan offering/default/jurnal dari akun parent (disembunyikan TB) ke leaf';

    /** @var array<string, list<array{pattern: string, leaf_name_contains?: string}>> */
    private array $keywordMaps = [
        '5000' => [
            ['pattern' => 'konsumsi', 'leaf_name_contains' => 'konsumsi'],
            ['pattern' => 'makan', 'leaf_name_contains' => 'konsumsi'],
            ['pattern' => 'hotel', 'leaf_name_contains' => 'event'],
            ['pattern' => 'trip', 'leaf_name_contains' => 'event'],
            ['pattern' => 'eo', 'leaf_name_contains' => 'event'],
        ],
        '6203' => [
            ['pattern' => 'parkir', 'leaf_name_contains' => 'umum'],
            ['pattern' => 'retribusi', 'leaf_name_contains' => 'retribusi'],
        ],
        '4000' => [
            ['pattern' => 'profit', 'leaf_name_contains' => 'lain'],
        ],
    ];

    /** @var array<string, string> */
    private array $fallbackLeafCodes = [
        '5000' => '5000.01',
        '4000' => '4000.08',
        '6203' => '6203.12',
        '1103' => '1103.00',
    ];

    /** Account-default keys that need a dedicated trading leaf (not a random detail sub-account). */
    private array $tradingDefaultKeys = [
        'accounts_receivable',
        'accounts_payable',
    ];

    public function handle(
        ReportService $reportService,
        AccountService $accountService,
        OpeningBalanceNameImportService $openingBalanceService,
    ): int {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        $companyIds = $this->resolveCompanyIds();
        if ($companyIds === []) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN: tidak ada perubahan tersimpan. Tambah --execute untuk menerapkan.');
        } else {
            $this->warn('LIVE: perubahan akan ditulis ke database.');
        }

        $asOf = $this->option('as-of') ?: Carbon::now()->toDateString();
        $previousCompanyId = session('current_company_id');

        try {
            foreach ($companyIds as $companyId) {
                $this->newLine();
                $company = Company::query()->findOrFail($companyId);
                $this->info("==== Company {$companyId} | {$company->name} ====");

                session(['current_company_id' => $companyId]);

                $before = $this->trialBalanceDiff($reportService, $accountService, $companyId, $asOf);
                $this->line(sprintf(
                    'TB as-of %s sebelum: D-C = %s%s',
                    $asOf,
                    $this->fmt($before),
                    $before === 0 ? ' (balance)' : ' (UNBALANCED)'
                ));

                $remapStats = $this->remapParentPostings($companyId, $dryRun);
                $this->table(
                    ['Item', 'Count'],
                    [
                        ['Parent accounts touched', (string) $remapStats['parents']],
                        ['Offerings remapped', (string) $remapStats['offerings']],
                        ['Account defaults remapped', (string) $remapStats['defaults']],
                        ['Journal entries remapped', (string) $remapStats['journals']],
                    ]
                );

                if ($remapStats['details'] !== []) {
                    $this->table(
                        ['From', 'To', 'Via', 'Refs'],
                        collect($remapStats['details'])->map(fn (array $d) => [
                            $d['from'],
                            $d['to'],
                            $d['via'],
                            $d['refs'],
                        ])->all()
                    );
                }

                if ($this->option('plug-unbalanced')) {
                    $plugged = $openingBalanceService->balanceUnbalancedJournalsToOpeningEquity(
                        $companyId,
                        $asOf,
                        ! $dryRun,
                    );
                    $this->line(sprintf(
                        'Plug unbalanced → OBE%s: scanned=%d fixed=%d net(D-C)=%s',
                        $dryRun ? ' (dry-run)' : '',
                        $plugged['scanned'],
                        $plugged['fixed'],
                        $this->fmt($plugged['net_debit_excess'])
                    ));
                }

                if (! $dryRun) {
                    $after = $this->trialBalanceDiff($reportService, $accountService, $companyId, $asOf);
                    $this->line(sprintf(
                        'TB as-of %s sesudah: D-C = %s%s',
                        $asOf,
                        $this->fmt($after),
                        $after === 0 ? ' (balance)' : ' (UNBALANCED)'
                    ));
                }
            }
        } finally {
            if ($previousCompanyId !== null) {
                session(['current_company_id' => $previousCompanyId]);
            } else {
                session()->forget('current_company_id');
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? 'Selesai dry-run. Jalankan ulang dengan --execute untuk menerapkan.'
            : 'Selesai. Perbaikan jurnal invoice payment: accounting:fix-unbalanced-invoice-payment-journals');

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveCompanyIds(): array
    {
        if ($this->option('all-companies')) {
            return Company::query()->orderBy('id')->pluck('id')->all();
        }

        $companyId = $this->option('company-id');
        if (! $companyId) {
            $this->error('Wajib --company-id= atau --all-companies.');

            return [];
        }

        if (! Company::query()->whereKey((int) $companyId)->exists()) {
            $this->error("Company {$companyId} tidak ditemukan.");

            return [];
        }

        return [(int) $companyId];
    }

    private function trialBalanceDiff(
        ReportService $reportService,
        AccountService $accountService,
        int $companyId,
        string $asOf,
    ): int {
        $asOfDateCarbon = Carbon::parse($asOf)->endOfDay();
        $startDateCarbon = Carbon::parse($reportService->getEarliestTransactionDate($companyId))->startOfDay();

        $allAccounts = $accountService
            ->getAccountBalances(
                $startDateCarbon->toDateTimeString(),
                $asOfDateCarbon->toDateTimeString(),
                [],
                null,
                $companyId
            )
            ->get();

        $excludedIds = Account::trialBalanceExcludedAccountIdsForCompany($companyId)->flip();
        $totalDebit = 0;
        $totalCredit = 0;

        foreach (AccountCategory::getOrderedCategories() as $category) {
            foreach ($allAccounts->where('category', $category) as $account) {
                if ($excludedIds->has($account->id)) {
                    continue;
                }

                $balances = $reportService->calculateAccountBalances($account);
                $ending = (int) ($balances['ending_balance'] ?? $balances['net_movement'] ?? 0);
                if ($ending === 0) {
                    continue;
                }

                $tb = $reportService->calculateTrialBalancesForAccount($account, $ending);
                $totalDebit += (int) ($tb['debit_balance'] ?? 0);
                $totalCredit += (int) ($tb['credit_balance'] ?? 0);
            }
        }

        return $totalDebit - $totalCredit;
    }

    private function fmt(int $minor): string
    {
        return number_format($minor / 100, 2, ',', '.');
    }

    /**
     * @return array{parents: int, offerings: int, defaults: int, journals: int, details: list<array{from: string, to: string, via: string, refs: string}>}
     */
    private function remapParentPostings(int $companyId, bool $dryRun): array
    {
        $parents = $this->findParentAccountsWithActivity($companyId);
        $details = [];
        $offeringsCount = 0;
        $defaultsCount = 0;
        $journalsCount = 0;

        foreach ($parents as $parent) {
            $children = $this->resolveChildLeaves($companyId, $parent);

            // System defaults on a header (e.g. AR → 1103) need a dedicated trading leaf.
            $defaultsOnParent = AccountDefault::query()
                ->withoutGlobalScope(CurrentCompanyScope::class)
                ->where('company_id', $companyId)
                ->where('account_id', $parent->id)
                ->get();

            $tradingLeaf = null;
            foreach ($defaultsOnParent as $default) {
                $keyValue = $default->key instanceof AccountDefaultKey
                    ? $default->key->value
                    : (string) $default->key;
                if (in_array($keyValue, $this->tradingDefaultKeys, true)) {
                    $tradingLeaf = $this->ensureTradingLeaf($companyId, $parent, $keyValue, $children, $dryRun);
                    if ($tradingLeaf && ! $children->contains('id', $tradingLeaf->id)) {
                        $children->push($tradingLeaf);
                    }
                    break;
                }
            }

            if ($children->isEmpty() && $tradingLeaf === null) {
                $this->warn("  Skip {$parent->code} {$parent->name}: tidak punya child leaf (parent_id / code-prefix).");

                continue;
            }

            $offerings = Offering::query()
                ->withoutGlobalScope(CurrentCompanyScope::class)
                ->where('company_id', $companyId)
                ->where(function ($q) use ($parent) {
                    $q->where('expense_account_id', $parent->id)
                        ->orWhere('income_account_id', $parent->id);
                })
                ->get();

            foreach ($offerings as $offering) {
                $leaf = $this->resolveLeaf($parent, $children, (string) $offering->name);
                $changed = false;

                if ((int) $offering->expense_account_id === (int) $parent->id) {
                    if (! $dryRun) {
                        $offering->expense_account_id = $leaf->id;
                    }
                    $changed = true;
                }
                if ((int) $offering->income_account_id === (int) $parent->id) {
                    if (! $dryRun) {
                        $offering->income_account_id = $leaf->id;
                    }
                    $changed = true;
                }

                if ($changed) {
                    if (! $dryRun) {
                        $offering->save();
                    }
                    $offeringsCount++;
                    $details[] = [
                        'from' => "{$parent->code}",
                        'to' => "{$leaf->code}",
                        'via' => 'offering',
                        'refs' => "#{$offering->id} {$offering->name}",
                    ];
                }
            }

            foreach ($defaultsOnParent as $default) {
                $key = $default->key instanceof AccountDefaultKey
                    ? $default->key
                    : AccountDefaultKey::tryFrom((string) $default->key);
                $keyValue = $key?->value ?? (string) $default->getRawOriginal('key');

                $leaf = in_array($keyValue, $this->tradingDefaultKeys, true) && $tradingLeaf
                    ? $tradingLeaf
                    : $this->resolveLeaf(
                        $parent,
                        $children,
                        $key === AccountDefaultKey::Profit ? 'profit' : $keyValue
                    );

                if (! $dryRun) {
                    $default->account_id = $leaf->id;
                    $default->save();
                }
                $defaultsCount++;
                $details[] = [
                    'from' => "{$parent->code}",
                    'to' => "{$leaf->code}",
                    'via' => 'account_default',
                    'refs' => $keyValue.($tradingLeaf && $leaf->id === $tradingLeaf->id ? ' (trading leaf)' : ''),
                ];
            }

            $defaultLeaf = $tradingLeaf
                ?? $this->resolveLeaf($parent, $children, $parent->code === '4000' ? 'profit' : null);

            $jeQuery = JournalEntry::query()
                ->withoutGlobalScope(CurrentCompanyScope::class)
                ->where('company_id', $companyId)
                ->where('account_id', $parent->id);

            $jeCount = (int) (clone $jeQuery)->count();
            if ($jeCount > 0 && $defaultLeaf) {
                $entries = (clone $jeQuery)->with('transaction')->get();
                $byLeaf = [];

                foreach ($entries as $entry) {
                    // Trading header (AR/AP): always move to dedicated trading leaf.
                    $leaf = $tradingLeaf
                        ?? $this->resolveLeafForJournalEntry($entry, $parent, $children, $defaultLeaf);
                    $byLeaf[$leaf->id][] = $entry->id;
                }

                foreach ($byLeaf as $leafId => $entryIds) {
                    $leaf = $children->firstWhere('id', $leafId)
                        ?? Account::query()->withoutGlobalScope(CurrentCompanyScope::class)->find($leafId);
                    if (! $dryRun) {
                        JournalEntry::query()
                            ->withoutGlobalScope(CurrentCompanyScope::class)
                            ->whereIn('id', $entryIds)
                            ->update(['account_id' => $leafId]);
                    }
                    $journalsCount += count($entryIds);
                    $details[] = [
                        'from' => "{$parent->code}",
                        'to' => $leaf?->code ?? (string) $leafId,
                        'via' => 'journal_entry',
                        'refs' => count($entryIds).' entries',
                    ];
                }
            }
        }

        return [
            'parents' => $parents->count(),
            'offerings' => $offeringsCount,
            'defaults' => $defaultsCount,
            'journals' => $journalsCount,
            'details' => $details,
        ];
    }

    /**
     * @return Collection<int, Account>
     */
    private function findParentAccountsWithActivity(int $companyId): Collection
    {
        $all = Account::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $companyId)
            ->get(['id', 'code', 'name', 'parent_id', 'company_id']);

        $parentIds = Account::parentAccountIdsFromCollection($all)->all();
        if ($parentIds === []) {
            return collect();
        }

        $activeParentIds = collect();

        $activeParentIds = $activeParentIds->merge(
            JournalEntry::query()
                ->withoutGlobalScope(CurrentCompanyScope::class)
                ->where('company_id', $companyId)
                ->whereIn('account_id', $parentIds)
                ->distinct()
                ->pluck('account_id')
        );

        $fromOffering = Offering::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $companyId)
            ->where(function ($q) use ($parentIds) {
                $q->whereIn('expense_account_id', $parentIds)
                    ->orWhereIn('income_account_id', $parentIds);
            })
            ->get(['expense_account_id', 'income_account_id']);

        foreach ($fromOffering as $o) {
            if (in_array((int) $o->expense_account_id, $parentIds, true)) {
                $activeParentIds->push((int) $o->expense_account_id);
            }
            if (in_array((int) $o->income_account_id, $parentIds, true)) {
                $activeParentIds->push((int) $o->income_account_id);
            }
        }

        $activeParentIds = $activeParentIds->merge(
            AccountDefault::query()
                ->withoutGlobalScope(CurrentCompanyScope::class)
                ->where('company_id', $companyId)
                ->whereIn('account_id', $parentIds)
                ->pluck('account_id')
        );

        $ids = $activeParentIds->unique()->filter()->values()->all();

        return $all->whereIn('id', $ids)->sortBy('code')->values();
    }

    /**
     * Children by parent_id, plus code-prefix siblings (e.g. 1103-001 under header 1103).
     *
     * @return Collection<int, Account>
     */
    private function resolveChildLeaves(int $companyId, Account $parent): Collection
    {
        $byParentId = Account::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $companyId)
            ->where('parent_id', $parent->id)
            ->orderByRaw('LENGTH(code), code')
            ->get();

        $code = trim((string) $parent->code);
        if ($code === '') {
            return $byParentId->values();
        }

        $byPrefix = Account::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $companyId)
            ->whereKeyNot($parent->id)
            ->where(function ($query) use ($code) {
                $query->where('code', 'like', $code.'.%')
                    ->orWhere('code', 'like', $code.'-%');

                if (ctype_digit($code) && strlen($code) >= 3) {
                    $query->orWhere(function ($nested) use ($code) {
                        $nested->where('code', 'like', $code.'%')
                            ->whereRaw('LENGTH(code) > ?', [strlen($code)]);
                    });
                }
            })
            ->orderByRaw('LENGTH(code), code')
            ->get();

        return $byParentId->concat($byPrefix)->unique('id')->values();
    }

    /**
     * Ensure a dedicated trading leaf for AR/AP defaults (not a random detail sub-ledger).
     */
    private function ensureTradingLeaf(
        int $companyId,
        Account $parent,
        string $defaultKey,
        Collection $children,
        bool $dryRun,
    ): ?Account {
        $wantedCode = ($this->fallbackLeafCodes[$parent->code] ?? null) ?: ($parent->code.'.00');
        $wantedName = match ($defaultKey) {
            'accounts_receivable' => 'Account Receivable IDR',
            'accounts_payable' => 'Account Payable IDR',
            default => $parent->name.' IDR',
        };

        $existing = $children->firstWhere('code', $wantedCode)
            ?? Account::query()
                ->withoutGlobalScope(CurrentCompanyScope::class)
                ->where('company_id', $companyId)
                ->where('code', $wantedCode)
                ->first()
            ?? $children->first(fn (Account $c) => strcasecmp($c->name, $wantedName) === 0);

        if ($existing) {
            // Prefer linking under parent when possible.
            if (! $dryRun && ! $existing->parent_id) {
                $existing->parent_id = $parent->id;
                $existing->save();
            }

            return $existing;
        }

        $this->line("  Create trading leaf {$wantedCode} {$wantedName} under {$parent->code}");

        if ($dryRun) {
            // Placeholder object for dry-run reporting (not persisted).
            $placeholder = $parent->replicate(['code', 'name', 'parent_id']);
            $placeholder->id = -1 * (int) $parent->id;
            $placeholder->code = $wantedCode;
            $placeholder->name = $wantedName;
            $placeholder->parent_id = $parent->id;

            return $placeholder;
        }

        return Account::query()->create([
            'company_id' => $companyId,
            'subtype_id' => $parent->subtype_id,
            'parent_id' => $parent->id,
            'category' => $parent->category,
            'type' => $parent->type,
            'code' => $wantedCode,
            'name' => $wantedName,
            'currency_code' => $parent->currency_code,
            'description' => 'Auto-created trading leaf for TB parent remap ('.$defaultKey.')',
            'archived' => false,
        ]);
    }

    /**
     * @param  Collection<int, Account>  $children
     */
    private function resolveLeaf(Account $parent, Collection $children, ?string $hint = null): Account
    {
        $hintLower = mb_strtolower(trim((string) $hint));

        if ($hintLower !== '') {
            foreach ($this->keywordMaps[$parent->code] ?? [] as $rule) {
                if (! str_contains($hintLower, $rule['pattern'])) {
                    continue;
                }
                $needle = mb_strtolower($rule['leaf_name_contains'] ?? '');
                $match = $children->first(
                    fn (Account $c) => $needle !== '' && str_contains(mb_strtolower($c->name), $needle)
                );
                if ($match) {
                    return $match;
                }
            }

            $direct = $children->first(function (Account $c) use ($hintLower) {
                return str_contains(mb_strtolower($c->name), $hintLower)
                    || str_contains(mb_strtolower((string) $c->code), $hintLower);
            });
            if ($direct) {
                return $direct;
            }
        }

        $fallbackCode = $this->fallbackLeafCodes[$parent->code] ?? null;
        if ($fallbackCode) {
            $byCode = $children->firstWhere('code', $fallbackCode);
            if ($byCode) {
                return $byCode;
            }
        }

        return $children->first();
    }

    /**
     * @param  Collection<int, Account>  $children
     */
    private function resolveLeafForJournalEntry(
        JournalEntry $entry,
        Account $parent,
        Collection $children,
        Account $defaultLeaf,
    ): Account {
        $transaction = $entry->transaction;
        if (! $transaction) {
            return $defaultLeaf;
        }

        $description = mb_strtolower((string) ($entry->description ?: $transaction->description));
        if (str_contains($description, 'profit')) {
            return $this->resolveLeaf($parent, $children, 'profit');
        }

        $type = $transaction->transactionable_type;
        $id = $transaction->transactionable_id;
        if (! $type || ! $id) {
            return $defaultLeaf;
        }

        $offeringNames = DB::table('document_line_items as dli')
            ->leftJoin('offerings as o', 'o.id', '=', 'dli.offering_id')
            ->where('dli.documentable_type', $type)
            ->where('dli.documentable_id', $id)
            ->pluck('o.name')
            ->filter()
            ->all();

        foreach ($offeringNames as $name) {
            $leaf = $this->resolveLeaf($parent, $children, (string) $name);
            if ($leaf->id !== $defaultLeaf->id || count($offeringNames) === 1) {
                return $leaf;
            }
        }

        if ($offeringNames !== []) {
            return $this->resolveLeaf($parent, $children, (string) $offeringNames[0]);
        }

        return $defaultLeaf;
    }
}

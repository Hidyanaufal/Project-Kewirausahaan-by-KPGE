<?php

namespace App\Transformers;

use App\DTO\AccountDTO;
use App\DTO\ReportCategoryDTO;
use App\DTO\ReportDTO;
use App\DTO\ReportTypeDTO;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;

class BalanceSheetReportTransformer extends SummaryReportTransformer
{
    protected string $totalAssets = '';

    protected string $totalLiabilities = '';

    protected string $totalEquity = '';

    public function __construct(ReportDTO $report)
    {
        parent::__construct($report);

        $this->calculateTotals();
    }

    public function getTitle(): string
    {
        return 'Balance Sheet (Standard)';
    }

    public function calculateTotals(): void
    {
        foreach ($this->report->categories as $accountCategoryName => $accountCategory) {
            match ($accountCategoryName) {
                'Assets' => $this->totalAssets = $accountCategory->summary->endingBalance ?? '',
                'Liabilities' => $this->totalLiabilities = $accountCategory->summary->endingBalance ?? '',
                'Equity' => $this->totalEquity = $accountCategory->summary->endingBalance ?? '',
            };
        }
    }

    public function getCategories(): array
    {
        $categories = [];

        foreach ($this->report->categories as $accountCategoryName => $accountCategory) {
            // Header for the main category
            $header = [];

            foreach ($this->getColumns() as $column) {
                $header[$column->getName()] = $column->getName() === 'account_name' ? $accountCategoryName : '';
            }

            // Category-level summary
            $categorySummary = [];
            foreach ($this->getColumns() as $column) {
                $categorySummary[$column->getName()] = match ($column->getName()) {
                    'account_name' => 'Total ' . $accountCategoryName,
                    'ending_balance' => $accountCategory->summary->endingBalance ?? '',
                    default => '',
                };
            }

            // Accounts directly under the main category
            $data = array_filter(
                array_map(function (AccountDTO $account) {
                    $row = [];

                    foreach ($this->getColumns() as $column) {
                        $row[$column->getName()] = match ($column->getName()) {
                            'account_code' => $account->accountCode,
                            'account_name' => [
                                'name' => $account->accountName,
                                'id' => $account->accountId ?? null,
                                'start_date' => $account->startDate,
                                'end_date' => $account->endDate,
                            ],
                            'ending_balance' => $account->balance->endingBalance ?? '',
                            default => '',
                        };
                    }

                    return $row;
                }, $accountCategory->accounts ?? []),
                function ($row) {
                    $balanceStr = $row['ending_balance'] ?? '';
                    if (blank($balanceStr)) {
                        return false; // Filter out empty balances
                    }
                    // Parse formatted money string to float
                    $balance = CurrencyConverter::convertToFloat($balanceStr);

                    return $balance != 0; // Keep only non-zero balances
                }
            );

            // Subcategories (types) under the main category
            $types = [];
            foreach ($accountCategory->types as $typeName => $type) {
                // Header for subcategory (type)
                $typeHeader = [];
                foreach ($this->getColumns() as $column) {
                    $typeHeader[$column->getName()] = $column->getName() === 'account_name' ? $typeName : '';
                }

                // Account data for the subcategory
                $typeData = array_filter(
                    array_map(function (AccountDTO $account) {
                        $row = [];

                        foreach ($this->getColumns() as $column) {
                            $row[$column->getName()] = match ($column->getName()) {
                                'account_code' => $account->accountCode,
                                'account_name' => [
                                    'name' => $account->accountName,
                                    'id' => $account->accountId ?? null,
                                    'start_date' => $account->startDate,
                                    'end_date' => $account->endDate,
                                ],
                                'ending_balance' => $account->balance->endingBalance ?? '',
                                default => '',
                            };
                        }

                        return $row;
                    }, $type->accounts),
                    function ($row) {
                        $balanceStr = $row['ending_balance'] ?? '';
                        if (blank($balanceStr)) {
                            return false; // Filter out empty balances
                        }
                        // Parse formatted money string to float
                        $balance = CurrencyConverter::convertToFloat($balanceStr);

                        return $balance != 0; // Keep only non-zero balances
                    }
                );

                // Subcategory (type) summary
                $typeSummary = [];
                foreach ($this->getColumns() as $column) {
                    $typeSummary[$column->getName()] = match ($column->getName()) {
                        'account_name' => 'Total ' . $typeName,
                        'ending_balance' => $type->summary->endingBalance ?? '',
                        default => '',
                    };
                }

                // Add subcategory (type) to the list
                $types[$typeName] = new ReportTypeDTO(
                    header: $typeHeader,
                    data: $typeData,
                    summary: $typeSummary,
                );
            }

            // Add the category to the final array with its direct accounts and subcategories (types)
            $categories[$accountCategoryName] = new ReportCategoryDTO(
                header: $header,
                data: $data, // Direct accounts under the category
                summary: $categorySummary,
                types: $types, // Subcategories (types) under the category
            );
        }

        return $categories;
    }

    public function getSummaryCategories(): array
    {
        $summaryCategories = [];

        $columns = $this->getSummaryColumns();

        foreach ($this->report->categories as $accountCategoryName => $accountCategory) {
            $categoryHeader = [];

            foreach ($columns as $column) {
                $categoryHeader[$column->getName()] = $column->getName() === 'account_name' ? $accountCategoryName : '';
            }

            $categorySummary = [];
            foreach ($columns as $column) {
                $categorySummary[$column->getName()] = match ($column->getName()) {
                    'account_name' => 'Total ' . $accountCategoryName,
                    'ending_balance' => $accountCategory->summary->endingBalance ?? '',
                    default => '',
                };
            }

            $types = [];
            $totalTypeSummaries = 0;

            // Iterate through each account type and calculate type summaries
            foreach ($accountCategory->types as $typeName => $type) {
                $typeSummary = [];
                $typeEndingBalance = 0;

                foreach ($columns as $column) {
                    $typeSummary[$column->getName()] = match ($column->getName()) {
                        'account_name' => 'Total ' . $typeName,
                        'ending_balance' => $type->summary->endingBalance ?? '',
                        default => '',
                    };

                    if ($column->getName() === 'ending_balance') {
                        $typeEndingBalance = $type->summary->endingBalance ?? 0;
                    }
                }

                $typeEndingBalance = money($typeEndingBalance, CurrencyAccessor::getDefaultCurrency())->getAmount();

                $totalTypeSummaries += $typeEndingBalance;

                $types[$typeName] = new ReportTypeDTO(
                    header: [],
                    data: [],
                    summary: $typeSummary,
                );
            }

            // Residual when category total ≠ sum of type subtotals (e.g. rounding); should be ~0 after correct grouping.
            if ($accountCategoryName === 'Equity') {
                $totalEquitySummary = $accountCategory->summary->endingBalance ?? 0;
                $totalEquitySummary = money($totalEquitySummary, CurrencyAccessor::getDefaultCurrency())->getAmount();
                $totalOtherEquity = $totalEquitySummary - $totalTypeSummaries;

                if ($totalOtherEquity != 0) {
                    $totalOtherEquityFormatted = money($totalOtherEquity, CurrencyAccessor::getDefaultCurrency(), true)->format();

                    // Add "Total Other Equity" as a new "type"
                    $otherEquitySummary = [];
                    foreach ($columns as $column) {
                        $otherEquitySummary[$column->getName()] = match ($column->getName()) {
                            'account_name' => 'Total Other Equity',
                            'ending_balance' => $totalOtherEquityFormatted,
                            default => '',
                        };
                    }

                    $types['Total Other Equity'] = new ReportTypeDTO(
                        header: [],
                        data: [],
                        summary: $otherEquitySummary,
                    );
                }
            }

            // Add the category with its types and summary to the final array
            $summaryCategories[$accountCategoryName] = new ReportCategoryDTO(
                header: $categoryHeader,
                data: [],
                summary: $categorySummary,
                types: $types,
            );
        }

        return $summaryCategories;
    }

    public function getOverallTotals(): array
    {
        $combined = $this->formatLiabilitiesPlusEquity();

        $columns = $this->getColumns();
        if ($columns === []) {
            return [
                'account_name' => 'Total Liabilities + Equity',
                'ending_balance' => $combined,
            ];
        }

        $totals = [];

        foreach ($columns as $column) {
            $totals[$column->getName()] = match ($column->getName()) {
                'account_name' => 'Total Liabilities + Equity',
                'ending_balance' => $combined,
                default => '',
            };
        }

        return $totals;
    }

    public function getSummaryOverallTotals(): array
    {
        $combined = $this->formatLiabilitiesPlusEquity();

        $totals = [];
        $columns = $this->getSummaryColumns();

        if ($columns === []) {
            return [
                'account_name' => 'Total Liabilities + Equity',
                'ending_balance' => $combined,
            ];
        }

        foreach ($columns as $column) {
            $totals[$column->getName()] = match ($column->getName()) {
                'account_name' => 'Total Liabilities + Equity',
                'ending_balance' => $combined,
                default => '',
            };
        }

        return $totals;
    }

    protected function formatLiabilitiesPlusEquity(): string
    {
        $currency = CurrencyAccessor::getDefaultCurrency();
        $liabilitiesCents = filled($this->totalLiabilities)
            ? CurrencyConverter::convertToCents($this->totalLiabilities, $currency)
            : 0;
        $equityCents = filled($this->totalEquity)
            ? CurrencyConverter::convertToCents($this->totalEquity, $currency)
            : 0;

        return CurrencyConverter::formatCentsToMoney($liabilitiesCents + $equityCents, $currency);
    }

    public function getSummary(): array
    {
        return [
            ['label' => 'Total Assets', 'value' => $this->totalAssets],
            ['label' => 'Total Liabilities', 'value' => $this->totalLiabilities],
            ['label' => 'Total Equity', 'value' => $this->totalEquity],
        ];
    }
}

<?php

namespace App\Filament\Company\Resources\Sales;

use App\Enums\Accounting\AdjustmentCategory;
use App\Enums\Accounting\AdjustmentStatus;
use App\Enums\Accounting\AdjustmentType;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\DocumentType;
use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Addons\AgingRange;
use App\Enums\Setting\PaymentTerms;
use App\Filament\Company\Resources\Addons\ProjectResource;
use App\Filament\Company\Resources\Sales\ClientResource\RelationManagers\InvoicesRelationManager;
use App\Filament\Company\Resources\Sales\InvoiceResource\Pages;
use App\Filament\Company\Resources\Sales\InvoiceResource\Widgets;
use App\Filament\Exports\Accounting\InvoiceExporter;
use App\Filament\Forms\Components\CreateAdjustmentSelect;
use App\Filament\Forms\Components\CreateClientSelect;
use App\Filament\Forms\Components\CreateCurrencySelect;
use App\Filament\Forms\Components\CreateOfferingSelect;
use App\Filament\Forms\Components\CustomTableRepeater;
use App\Filament\Forms\Components\DocumentFooterSection;
use App\Filament\Forms\Components\DocumentHeaderSection;
use App\Filament\Forms\Components\DocumentTotals;
use App\Filament\Tables\Columns;
use App\Filament\Tables\Filters\DateRangeFilter;
use App\Helpers\AuthHelper;
use App\Models\Accounting\Adjustment;
use App\Models\Accounting\DocumentLineItem;
use App\Models\Accounting\Invoice;
use App\Models\Addons\TaxCode;
use App\Models\Common\Client;
use App\Models\Common\Offering;
use App\Models\Company;
use App\Services\ExportService;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use App\Utilities\RateCalculator;
use Awcodes\TableRepeater\Header;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;
use Guava\FilamentClusters\Forms\Cluster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class InvoiceResource extends Resource
{
    use \App\Concerns\HasPermissionCheck;

    protected static ?string $model = Invoice::class;

    public static function form(Form $form): Form
    {
        $company = Auth::user()->currentCompany;

        $settings = $company->defaultInvoice;

        return $form
            ->schema([
                DocumentHeaderSection::make('Invoice Header')
                    ->defaultHeader($settings->header)
                    ->defaultSubheader($settings->subheader)
                    ->visible(false),
                Forms\Components\Section::make('Invoice Details')
                    ->schema([
                        Forms\Components\Split::make([
                            Forms\Components\Group::make([
                                ProjectResource::options(),
                                CreateClientSelect::make('client_id')
                                    ->options(fn() => Client::where('company_id', AuthHelper::currentCompany())->pluck('name', 'id'))
                                    ->label('Client')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                        if (! $state) {
                                            return;
                                        }

                                        $currencyCode = Client::find($state)?->currency_code;

                                        if ($currencyCode) {
                                            $set('currency_code', $currencyCode);
                                        }
                                    }),
                                CreateCurrencySelect::make('currency_code')
                                    ->disabled(function (?Invoice $record) {
                                        return $record?->hasPayments();
                                    }),
                                Forms\Components\Textarea::make('recipient_details')
                                    ->label('Recipient Details')
                                    ->placeholder('Enter recipient details (e.g. Manager, PT Solusi)')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ]),
                            Forms\Components\Group::make([
                                Forms\Components\TextInput::make('invoice_number')
                                    ->label('Invoice number')
                                    ->default(static fn() => Invoice::getNextDocumentNumber()),
                                Cluster::make([
                                    Forms\Components\DatePicker::make('date')
                                        ->label('Invoice date')
                                        ->live()
                                        ->default(company_today()->toDateString())
                                        ->disabled(function (?Invoice $record) {
                                            return $record?->hasPayments();
                                        })
                                        ->columnSpan(2)
                                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                            $date = Carbon::parse($state)->toDateString();
                                            $dueDate = Carbon::parse($get('due_date'))->toDateString();

                                            if ($date && $dueDate && $date > $dueDate) {
                                                $set('due_date', $date);
                                            }

                                            // Update due date based on payment terms if selected
                                            $paymentTerms = $get('payment_terms');
                                            if ($date && $paymentTerms && $paymentTerms !== 'custom') {
                                                $terms = PaymentTerms::parse($paymentTerms);
                                                $set('due_date', Carbon::parse($date)->addDays($terms->getDays())->toDateString());
                                            }
                                        }),
                                    Forms\Components\Select::make('payment_terms')
                                        ->label('Payment terms')
                                        ->options(function () {
                                            return collect(PaymentTerms::cases())
                                                ->mapWithKeys(function (PaymentTerms $paymentTerm) {
                                                    return [$paymentTerm->value => $paymentTerm->getLabel()];
                                                })
                                                ->put('custom', 'Custom')
                                                ->toArray();
                                        })
                                        ->selectablePlaceholder(false)
                                        ->default($settings->payment_terms->value)
                                        ->live()
                                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                            if (! $state || $state === 'custom') {
                                                return;
                                            }

                                            $date = $get('date');
                                            if ($date) {
                                                $terms = PaymentTerms::parse($state);
                                                $set('due_date', Carbon::parse($date)->addDays($terms->getDays())->toDateString());
                                            }
                                        }),
                                ])
                                    ->label('Invoice date')
                                    ->columns(3),
                                Forms\Components\DatePicker::make('due_date')
                                    ->label('Payment due')
                                    ->default(function () use ($settings) {
                                        return company_today()->addDays($settings->payment_terms->getDays())->toDateString();
                                    })
                                    ->minDate(static function (Forms\Get $get) {
                                        return Carbon::parse($get('date'))->toDateString() ?? company_today()->toDateString();
                                    })
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                        if (! $state) {
                                            return;
                                        }

                                        $invoiceDate = $get('date');
                                        $paymentTerms = $get('payment_terms');

                                        if (! $invoiceDate || $paymentTerms === 'custom' || ! $paymentTerms) {
                                            return;
                                        }

                                        $term = PaymentTerms::parse($paymentTerms);
                                        $expectedDueDate = Carbon::parse($invoiceDate)->addDays($term->getDays());

                                        if (! Carbon::parse($state)->isSameDay($expectedDueDate)) {
                                            $set('payment_terms', 'custom');
                                        }
                                    }),
                                Forms\Components\Select::make('discount_method')
                                    ->label('Discount method')->hidden()
                                    ->options(DocumentDiscountMethod::class)
                                    ->softRequired()
                                    ->default($settings->discount_method)
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        $discountMethod = DocumentDiscountMethod::parse($state);

                                        if ($discountMethod->isPerDocument()) {
                                            $set('lineItems.*.salesDiscounts', []);
                                        }
                                    })
                                    ->live(),
                            ])->grow(true),
                        ])->from('md'),
                        CustomTableRepeater::make('lineItems')
                            ->hiddenLabel()
                            ->relationship()
                            ->saveRelationshipsUsing(null)
                            ->dehydrated(true)
                            ->reorderable()
                            ->orderColumn('line_number')
                            ->reorderAtStart()
                            ->cloneable()
                            ->addActionLabel('Add an item')
                            ->headers(function (Forms\Get $get) use ($settings) {
                                $hasDiscounts = DocumentDiscountMethod::parse($get('discount_method'))->isPerLineItem();

                                $headers = [
                                    Header::make($settings->resolveColumnLabel('item_name', 'Items'))
                                        ->width('30%'),
                                    Header::make($settings->resolveColumnLabel('unit_name', 'Quantity'))
                                        ->width('10%'),
                                    Header::make($settings->resolveColumnLabel('price_name', 'Price'))
                                        ->width('10%'),
                                ];

                                if ($hasDiscounts) {
                                    $headers[] = Header::make('Adjustments')->width('30%');
                                } else {
                                    $headers[] = Header::make('Taxes')->width('30%');
                                }

                                $headers[] = Header::make($settings->resolveColumnLabel('amount_name', 'Amount'))
                                    ->width('10%')
                                    ->align('right');

                                return $headers;
                            })
                            ->schema([
                                Forms\Components\Group::make([
                                    CreateOfferingSelect::make('offering_id')
                                        ->label('Item')
                                        ->hiddenLabel()
                                        ->placeholder('Select item')
                                        ->required()
                                        ->live()
                                        ->inlineSuffix()
                                        ->sellable()
                                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state, ?DocumentLineItem $record) {
                                            $offeringId = $state;
                                            $discountMethod = DocumentDiscountMethod::parse($get('../../discount_method'));
                                            $isPerLineItem = $discountMethod->isPerLineItem();

                                            $existingTaxIds = [];
                                            $existingDiscountIds = [];

                                            if ($record) {
                                                $existingTaxIds = $record->salesTaxes()->pluck('adjustments.id')->toArray();
                                                if ($isPerLineItem) {
                                                    $existingDiscountIds = $record->salesDiscounts()->pluck('adjustments.id')->toArray();
                                                }
                                            }

                                            $with = [
                                                'salesTaxes' => static function ($query) use ($existingTaxIds) {
                                                    $query->where(static function ($query) use ($existingTaxIds) {
                                                        $query->where('status', AdjustmentStatus::Active)
                                                            ->orWhereIn('adjustments.id', $existingTaxIds);
                                                    });
                                                },
                                            ];

                                            if ($isPerLineItem) {
                                                $with['salesDiscounts'] = static function ($query) use ($existingDiscountIds) {
                                                    $query->where(static function ($query) use ($existingDiscountIds) {
                                                        $query->where('status', AdjustmentStatus::Active)
                                                            ->orWhereIn('adjustments.id', $existingDiscountIds);
                                                    });
                                                };
                                            }

                                            $offeringRecord = Offering::with($with)->find($offeringId);

                                            if (! $offeringRecord) {
                                                return;
                                            }

                                            $unitPrice = CurrencyConverter::convertCentsToFormatSimple($offeringRecord->price, 'USD');

                                            $set('description', $offeringRecord->description);
                                            $set('unit_price', $unitPrice);
                                            $set('salesTaxes', $offeringRecord->salesTaxes->pluck('id')->toArray());

                                            if ($isPerLineItem) {
                                                $set('salesDiscounts', $offeringRecord->salesDiscounts->pluck('id')->toArray());
                                            }
                                        }),
                                    Forms\Components\Textarea::make('description')
                                        ->placeholder('Enter item description')
                                        ->hiddenLabel(),
                                ])->columnSpan(1),
                                Forms\Components\Group::make([
                                    Forms\Components\TextInput::make('quantity')
                                        ->required()
                                        ->numeric()
                                        ->live()
                                        ->hiddenLabel()
                                        ->maxValue(9999999999.99)
                                        ->default(1),
                                    Forms\Components\TextInput::make('unit')
                                        ->placeholder('Enter unit description')
                                        ->hiddenLabel(),
                                ]),
                                Forms\Components\TextInput::make('unit_price')
                                    ->hiddenLabel()
                                    ->money(useAffix: false)
                                    ->live()
                                    ->default(0),
                                Forms\Components\Group::make([
                                    CreateAdjustmentSelect::make('salesTaxes')
                                        ->label('Taxes')
                                        ->hiddenLabel()
                                        ->placeholder('Select taxes')
                                        ->category(AdjustmentCategory::Tax)
                                        ->type(AdjustmentType::Sales)
                                        ->adjustmentsRelationship('salesTaxes')
                                        ->saveRelationshipsUsing(null)
                                        ->dehydrated(true)
                                        ->inlineSuffix()
                                        ->preload()
                                        ->multiple()
                                        ->live()
                                        ->searchable(),
                                    CreateAdjustmentSelect::make('salesDiscounts')
                                        ->label('Discounts')
                                        ->hiddenLabel()
                                        ->placeholder('Select discounts')
                                        ->category(AdjustmentCategory::Discount)
                                        ->type(AdjustmentType::Sales)
                                        ->adjustmentsRelationship('salesDiscounts')
                                        ->saveRelationshipsUsing(null)
                                        ->dehydrated(true)
                                        ->inlineSuffix()
                                        ->multiple()
                                        ->live()
                                        ->hidden(function (Forms\Get $get) {
                                            $discountMethod = DocumentDiscountMethod::parse($get('../../discount_method'));

                                            return $discountMethod->isPerDocument();
                                        })
                                        ->searchable(),
                                ])->columnSpan(1),
                                Forms\Components\Placeholder::make('total')
                                    ->hiddenLabel()
                                    ->extraAttributes(['class' => 'text-left sm:text-right'])
                                    ->content(function (Forms\Get $get) {
                                        $quantity = max((float) ($get('quantity') ?? 0), 0);
                                        $unitPrice = CurrencyConverter::isValidAmount($get('unit_price'), 'USD')
                                            ? CurrencyConverter::convertToFloat($get('unit_price'), 'USD')
                                            : 0;
                                        $salesTaxes = $get('salesTaxes') ?? [];
                                        $salesDiscounts = $get('salesDiscounts') ?? [];
                                        $currencyCode = $get('../../currency_code') ?? CurrencyAccessor::getDefaultCurrency();

                                        $subtotal = $quantity * $unitPrice;

                                        $subtotalInCents = CurrencyConverter::convertToCents($subtotal, $currencyCode);

                                        $taxAmountInCents = Adjustment::whereIn('id', $salesTaxes)
                                            ->get()
                                            ->sum(function (Adjustment $adjustment) use ($subtotalInCents) {
                                                if ($adjustment->computation->isPercentage()) {
                                                    return RateCalculator::calculatePercentage($subtotalInCents, $adjustment->getRawOriginal('rate'));
                                                } else {
                                                    return $adjustment->getRawOriginal('rate');
                                                }
                                            });

                                        $discountAmountInCents = Adjustment::whereIn('id', $salesDiscounts)
                                            ->get()
                                            ->sum(function (Adjustment $adjustment) use ($subtotalInCents) {
                                                if ($adjustment->computation->isPercentage()) {
                                                    return RateCalculator::calculatePercentage($subtotalInCents, $adjustment->getRawOriginal('rate'));
                                                } else {
                                                    return $adjustment->getRawOriginal('rate');
                                                }
                                            });

                                        // Final total
                                        $totalInCents = $subtotalInCents + ($taxAmountInCents - $discountAmountInCents);
                                        return CurrencyConverter::formatCentsToMoney($totalInCents, $currencyCode);
                                    }),
                            ]),
                        DocumentTotals::make()
                            ->type(DocumentType::Invoice),
                        Forms\Components\RichEditor::make('terms')
                            ->default($settings->terms)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Contract Information')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Group::make([
                            Forms\Components\TextInput::make('contract_number')
                                ->label('Contract Number')
                                ->maxLength(100)
                                ->placeholder('Enter contract number'),
                        ])->columns(2)->columnSpanFull(),

                        Forms\Components\TextInput::make('po_reference_number')
                            ->label('PO Reference Number')
                            ->maxLength(100)
                            ->placeholder('Enter PO reference number'),

                        Forms\Components\DatePicker::make('po_date')
                            ->label('PO Date')
                            ->placeholder('Select PO date')
                            ->native(true),

                        Forms\Components\TextInput::make('spk_number')
                            ->label('SPK Number')
                            ->maxLength(100)
                            ->placeholder('Enter SPK number'),

                        Forms\Components\DatePicker::make('spk_date')
                            ->label('SPK Date')
                            ->placeholder('Select SPK date')
                            ->native(true),

                        Forms\Components\Toggle::make('hide_offering_items')
                            ->label('Hide Offering Items')
                            ->helperText('When enabled, individual offering items will be hidden and only totals will be shown aligned with the project')
                            ->default(false)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Tax Detail')
                    ->columns(3)
                    ->visible(fn() => AuthHelper::isAdmin())
                    ->schema([
                        Forms\Components\Select::make('tax_code_id')
                            ->label('Tax Code')
                            ->native(false)
                            ->options(function () {
                                return TaxCode::query()
                                    ->get()
                                    ->mapWithKeys(function ($taxCode) {
                                        return [
                                            $taxCode->id => "{$taxCode->code} - {$taxCode->description}",
                                        ];
                                    });
                            })
                            ->required(fn(?Invoice $record) => $record?->isFinalized())
                            ->helperText(fn(?Invoice $record) => $record?->isFinalized() ? 'Tax code is required after invoice is finalized.' : null),
                        Forms\Components\TextInput::make('tax_number')
                            ->label('Tax Number')
                            ->required(fn(?Invoice $record) => $record?->isFinalized())
                            ->helperText(fn(?Invoice $record) => $record?->isFinalized() ? 'Tax number is required after invoice is finalized.' : null),
                        Forms\Components\DatePicker::make('tax_date')
                            ->label('Tax Date')
                            ->required(fn(?Invoice $record) => $record?->isFinalized())
                            ->helperText(fn(?Invoice $record) => $record?->isFinalized() ? 'Tax date is required after invoice is finalized.' : null),
                    ]),
                DocumentFooterSection::make('Invoice Footer')
                    ->defaultFooter($settings->footer)
                    ->visible(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(function (Builder $query, Tables\Contracts\HasTable $livewire) {
                $query->with([
                    'payments' => fn ($paymentsQuery) => $paymentsQuery
                        ->select(['id', 'transactionable_type', 'transactionable_id', 'transaction_code', 'posted_at'])
                        ->orderBy('id'),
                ]);

                if (property_exists($livewire, 'recurringInvoice')) {
                    $recurringInvoiceId = $livewire->recurringInvoice;

                    if (! empty($recurringInvoiceId)) {
                        $query->where('recurring_invoice_id', $recurringInvoiceId);
                    }
                }

                return $query;
            })
            ->columns([
                Columns::id(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due')
                    ->asRelativeDay()
                    ->sortable()
                    ->hideOnTabs(['draft']),
                Tables\Columns\TextColumn::make('date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tax_number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Number')
                    ->searchable()
                    ->description(function (Invoice $record) {
                        return $record->source_type?->getLabel();
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('client.name')
                    ->sortable()
                    ->searchable()
                    ->hiddenOn(InvoicesRelationManager::class),
                Tables\Columns\TextColumn::make('total')
                    ->currencyWithConversion(static fn(Invoice $record) => $record->currency_code)
                    ->sortable()
                    ->toggleable()
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('amount_paid')
                    ->label('Amount paid')
                    ->currencyWithConversion(static fn(Invoice $record) => $record->currency_code)
                    ->sortable()
                    ->alignEnd()
                    ->showOnTabs(['unpaid']),
                Tables\Columns\TextColumn::make('payment_transaction_codes')
                    ->label('Payment code')
                    ->getStateUsing(function (Invoice $record): ?string {
                        if (! $record->hasPayments()) {
                            return null;
                        }

                        return $record->payments
                            ->pluck('transaction_code')
                            ->filter()
                            ->unique()
                            ->implode(', ') ?: null;
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('payments', function (Builder $paymentsQuery) use ($search): void {
                            $paymentsQuery->where('transaction_code', 'like', "%{$search}%");
                        });
                    })
                    ->placeholder('—')
                    ->toggleable()
                    ->hideOnTabs(['draft']),
                Tables\Columns\TextColumn::make('amount_due')
                    ->label('Amount due')
                    ->currencyWithConversion(static fn(Invoice $record) => $record->currency_code)
                    ->sortable()
                    ->alignEnd()
                    ->hideOnTabs(['draft']),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('client')
                    ->options(fn() => Client::where('company_id', AuthHelper::currentCompany())->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->hiddenOn(InvoicesRelationManager::class),
                Tables\Filters\SelectFilter::make('status')
                    ->options(InvoiceStatus::class)
                    ->multiple(),
                Tables\Filters\TernaryFilter::make('has_payments')
                    ->label('Has payments')
                    ->native(false)
                    ->placeholder('All Status')
                    ->queries(
                        true: fn(Builder $query) => $query->whereHas('payments'),
                        false: fn(Builder $query) => $query->whereDoesntHave('payments'),
                    ),
                Tables\Filters\Filter::make('aging')
                    ->label('Aging (Days)')
                    ->indicateUsing(function (array $state) {
                        if (blank($state['aging_range'] ?? null)) {
                            return [];
                        }
                        $aging = AgingRange::from($state['aging_range']);
                        $label = 'Aging (Days) ' . $aging->getLabel();
                        return Tables\Filters\Indicator::make("{$label}")->removable(true);
                    })
                    ->form([
                        Select::make('aging_range')
                            ->label('Select Aging Range')
                            ->options(AgingRange::class)
                            ->required()
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $aging_range = $data['aging_range'] ?? null;
                        if ($aging_range) {
                            $query->whereIn('status', ['overdue', 'unsent']);
                            switch ($aging_range) {
                                case '1_to_30':
                                    return $query->whereRaw('DATEDIFF(due_date, CURDATE()) BETWEEN -30 AND 0');
                                case '31_to_60':
                                    return $query->whereRaw('DATEDIFF(due_date, CURDATE()) BETWEEN -60 AND -31');
                                case '61_to_90':
                                    return $query->whereRaw('DATEDIFF(due_date, CURDATE()) BETWEEN -90 AND -61');
                                case 'over_90':
                                    return $query->whereRaw('DATEDIFF(due_date, CURDATE()) < -90');
                                default:
                                    return $query; // No filtering if no range is selected
                            }
                        }
                        return $query;
                    }),
                DateRangeFilter::make('date')
                    ->fromLabel('From date')
                    ->untilLabel('To date')
                    ->indicatorLabel('Date'),
                DateRangeFilter::make('due_date')
                    ->fromLabel('From due date')
                    ->untilLabel('To due date')
                    ->indicatorLabel('Due'),
            ])
            ->headerActions([
                Tables\Actions\ExportAction::make()
                    ->label('Export Excel')
                    ->exporter(InvoiceExporter::class),
                Tables\Actions\Action::make('exportPdf')
                    ->label('Export PDF')
                    ->color('danger')
                    ->action(function (Tables\Contracts\HasTable $livewire) {
                        $companyId = AuthHelper::currentCompany();
                        $company = Company::find($companyId);
                        $query = $livewire->getFilteredTableQuery();
                        $now = now()->format('Y-m-d_H-i-s');
                        $records = $query->with(['client'])->get();
                        $filename = "{$company->name} - Invoice List - {$now}.pdf";

                        $columns = [
                            'Invoice Number',
                            'Tax Number',
                            'Due Date',
                            'Client Name',
                            'Status',
                            'Total',
                            'Amount Paid',
                            'Amount Due',
                            'Profit',
                            'Subtotal',
                            'Tax Total',
                        ];
                        $rows = collect();

                        foreach ($records as $record) {
                            $rows->push([
                                'data' => [
                                    '__str' . $record->invoice_number, // Invoice Number
                                    '__str' . $record->tax_number, // Tax Number
                                    $record->due_date->format('d M Y'), // Due Date
                                    $record->client->name, // Client Name
                                    $record->status->getLabel(), // Status
                                    $record->total / 100, // Total
                                    $record->amount_paid / 100, // Amount Paid
                                    $record->amount_due / 100, // Amount Due
                                    $record->profit_total / 100, // Profit
                                    $record->subtotal / 100, // Subtotal
                                    $record->tax_total / 100, // Tax Total
                                ],
                                'style' => [],
                            ]);
                        }

                        $data = [
                            'filename'           => $filename,
                            'company_name'       => $company->name,
                            'report_title'       => "Invoice List",
                            'report_period'      => 'As of ' . now()->format('d M Y'),
                            'columns'            => $columns,
                            'rows'               => $rows->toArray(),
                            'amount_decimal_places' => 0,
                        ];

                        $exportService = new ExportService();

                        $pdf = SnappyPdf::loadView('pdf.reports-general', [
                            'data' => $data,
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

                        return response()->streamDownload(function () use ($pdf) {
                            echo $pdf->output();
                        }, $data['filename']);
                    })

            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ActionGroup::make([
                        Tables\Actions\EditAction::make()
                            ->url(static fn(Invoice $record) => Pages\EditInvoice::getUrl(['record' => $record]))
                            ->visible(function (Invoice $record) {
                                return AuthHelper::isAdmin() && $record->status !== InvoiceStatus::Cancelled;
                            }),
                        Tables\Actions\ViewAction::make()
                            ->url(static fn(Invoice $record) => Pages\ViewInvoice::getUrl(['record' => $record])),
                        Invoice::getCancelInvoiceAction(Tables\Actions\Action::class)
                            ->visible(function (Invoice $record) {
                                return AuthHelper::isAdmin() && $record->status !== InvoiceStatus::Cancelled;
                            }),
                        Invoice::getUnifiedApproveAction(Tables\Actions\Action::class)
                            ->visible(function (Invoice $record) {
                                return AuthHelper::isAdmin() && $record->status !== InvoiceStatus::Cancelled;
                            }),
                        Invoice::getMarkAsSentAction(Tables\Actions\Action::class)
                            ->visible(function (Invoice $record) {
                                return AuthHelper::isAdmin() && $record->status !== InvoiceStatus::Cancelled;
                            }),
                        Tables\Actions\Action::make('recordPayment')
                            ->label('Record Payment')
                            ->icon('heroicon-m-credit-card')
                            ->visible(function (Invoice $record) {
                                return $record->canRecordPayment();
                            })
                            ->url(fn(Invoice $record) => Pages\RecordPayments::getUrl([
                                'tableFilters' => [
                                    'client_id' => ['value' => $record->client_id],
                                    'currency_code' => ['value' => $record->currency_code],
                                ],
                                'invoiceId' => $record->id,
                            ]))
                            ->openUrlInNewTab(false),
                    ])->dropdown(false),
                    Tables\Actions\DeleteAction::make()
                        ->visible(function (Invoice $record) {
                            return AuthHelper::isAdmin() && $record->status !== InvoiceStatus::Cancelled;
                        }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('approveDrafts')
                        ->label('Approve')
                        ->icon('heroicon-o-check-circle')
                        ->databaseTransaction()
                        ->successNotificationTitle('Invoices approved')
                        ->failureNotificationTitle('Failed to Approve Invoices')
                        ->before(function (Collection $records, Tables\Actions\BulkAction $action) {
                            $isInvalid = $records->contains(fn(Invoice $record) => ! $record->canBeApproved());

                            if ($isInvalid) {
                                Notification::make()
                                    ->title('Approval failed')
                                    ->body('Only draft invoices can be approved. Please adjust your selection and try again.')
                                    ->persistent()
                                    ->danger()
                                    ->send();

                                $action->cancel(true);
                            }
                        })
                        ->action(function (Collection $records, Tables\Actions\BulkAction $action) {
                            $records->each(function (Invoice $record) {
                                $record->approveDraft();
                            });

                            $action->success();
                        }),
                    Tables\Actions\BulkAction::make('markAsSent')
                        ->label('Mark as sent')
                        ->icon('heroicon-o-paper-airplane')
                        ->databaseTransaction()
                        ->successNotificationTitle('Invoices sent')
                        ->failureNotificationTitle('Failed to Mark Invoices as Sent')
                        ->before(function (Collection $records, Tables\Actions\BulkAction $action) {
                            $isInvalid = $records->contains(fn(Invoice $record) => ! $record->canBeMarkedAsSent());

                            if ($isInvalid) {
                                Notification::make()
                                    ->title('Sending failed')
                                    ->body('Only unsent invoices can be marked as sent. Please adjust your selection and try again.')
                                    ->persistent()
                                    ->danger()
                                    ->send();

                                $action->cancel(true);
                            }
                        })
                        ->action(function (Collection $records, Tables\Actions\BulkAction $action) {
                            $records->each(function (Invoice $record) {
                                $record->markAsSent();
                            });

                            $action->success();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'record-payments' => Pages\RecordPayments::route('/record-payments'),
            'create' => Pages\CreateInvoice::route('/create'),
            'view' => Pages\ViewInvoice::route('/{record}'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            Widgets\InvoiceOverview::class,
        ];
    }
}

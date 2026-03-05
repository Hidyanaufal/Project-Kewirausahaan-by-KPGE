<?php

namespace App\Filament\Forms\Components;

use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\DocumentType;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;

class DocumentTotals extends Grid
{
    protected string $view = 'filament.forms.components.document-totals';

    protected DocumentType $documentType = DocumentType::Invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema([
            Select::make('profit_computation')
                ->hiddenLabel()
                ->options([
                    'percentage' => 'Percentage'
                ])
                ->default(AdjustmentComputation::Percentage)
                ->visible(fn() => in_array($this->getType(), [DocumentType::Invoice, DocumentType::Bill, DocumentType::Estimate]))
                ->selectablePlaceholder(false)
                ->live(),
            TextInput::make('profit_rate')
                ->hiddenLabel()
                ->live()
                ->visible(fn() => in_array($this->getType(), [DocumentType::Invoice, DocumentType::Bill, DocumentType::Estimate]))
                ->extraInputAttributes(['class' => 'text-right'])
                ->rate(
                    computation: static fn(Get $get) => $get('profit_computation'),
                    currency: static fn(Get $get) => $get('currency_code'),
                ),
            Select::make('discount_computation')
                ->hiddenLabel()
                ->options(AdjustmentComputation::class)
                ->default(AdjustmentComputation::Percentage)
                ->selectablePlaceholder(false)
                ->live(),
            TextInput::make('discount_rate')
                ->hiddenLabel()
                ->live()
                ->extraInputAttributes(['class' => 'text-right'])
                ->rate(
                    computation: static fn(Get $get) => $get('discount_computation'),
                    currency: static fn(Get $get) => $get('currency_code'),
                ),
        ]);
    }

    public function type(DocumentType | string $type): static
    {
        if (is_string($type)) {
            $type = DocumentType::from($type);
        }

        $this->documentType = $type;

        return $this;
    }

    public function getType(): DocumentType
    {
        return $this->documentType;
    }
}

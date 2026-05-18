<?php

use App\DTO\DocumentPreviewDTO;
use App\Enums\Accounting\DocumentType;
use App\Models\Setting\DocumentDefault;

function previewFormData(DocumentDefault $settings): array
{
    return [
        'payment_terms' => $settings->payment_terms->value,
        'font' => $settings->font->value,
        'template' => $settings->template->value,
        'number_prefix' => $settings->number_prefix,
        'item_name' => $settings->item_name?->toArray(),
        'unit_name' => $settings->unit_name?->toArray(),
        'price_name' => $settings->price_name?->toArray(),
        'amount_name' => $settings->amount_name?->toArray(),
    ];
}

it('builds document preview from settings without missing constructor arguments', function () {
    $settings = $this->testCompany->documentDefaults()
        ->where('type', DocumentType::Invoice)
        ->first();

    expect($settings)->not->toBeNull();

    $document = DocumentPreviewDTO::fromSettings($settings, previewFormData($settings));

    expect($document)->toBeInstanceOf(DocumentPreviewDTO::class)
        ->and($document->discountRateLabel)->toBe('10%');
});

it('builds document preview from settings with form data', function () {
    $settings = $this->testCompany->documentDefaults()
        ->where('type', DocumentType::Invoice)
        ->first();

    $document = DocumentPreviewDTO::fromSettings($settings, [
        ...previewFormData($settings),
        'header' => 'Live Header',
    ]);

    expect($document)->toBeInstanceOf(DocumentPreviewDTO::class)
        ->and($document->header)->toBe('Live Header')
        ->and($document->discountRateLabel)->toBe('10%');
});

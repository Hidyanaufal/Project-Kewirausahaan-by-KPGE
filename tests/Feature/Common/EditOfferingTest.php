<?php

use App\Filament\Company\Resources\Common\OfferingResource;
use App\Filament\Company\Resources\Common\OfferingResource\Pages\EditOffering;
use App\Models\Common\Offering;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

it('normalizes attribute checkbox state', function () {
    expect(OfferingResource::normalizeAttributeChoices(true))->toBe(['Sellable', 'Purchasable'])
        ->and(OfferingResource::normalizeAttributeChoices(null))->toBe([])
        ->and(OfferingResource::normalizeAttributeChoices(['Sellable']))->toBe(['Sellable'])
        ->and(OfferingResource::hasSellableAttribute(true))->toBeTrue()
        ->and(OfferingResource::hasPurchasableAttribute(true))->toBeTrue();
});

it('maps attribute choices to sellable flags when saving', function () {
    $data = OfferingResource::applyAttributeChoices([
        'attributes' => true,
        'name' => 'Test Offering',
    ]);

    expect($data['sellable'])->toBeTrue()
        ->and($data['purchasable'])->toBeTrue()
        ->and($data)->not->toHaveKey('attributes');
});

it('does not throw when toggling attributes on edit offering form', function () {
    $this->testUser->update(['is_super_admin' => true]);

    Filament::setCurrentPanel(Filament::getPanel('company'));

    $offering = Offering::factory()
        ->for($this->testCompany)
        ->create([
            'sellable' => false,
            'purchasable' => false,
        ]);

    livewire(EditOffering::class, ['record' => $offering->getRouteKey()])
        ->assertFormSet([
            'attributes' => [],
        ])
        ->set('data.attributes', true)
        ->set('data.attributes', ['Sellable'])
        ->set('data.attributes', ['Sellable', 'Purchasable']);
});

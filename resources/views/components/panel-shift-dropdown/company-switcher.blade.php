@props([
    'icon' => null,
    'currentTenant' => null,
])

@php
    $companies = \App\Models\Company::where('id', '!=', $currentTenant->id)->get();

    $currentTenantName = filament()->getTenantName($currentTenant);
    $currentCompany = auth()->user()->currentCompany;
    $currentCompanyOwner = $currentCompany->owner;
    $items = filament()->getTenantMenuItems();

    $profileItem = $items['profile'] ?? null;
    $profileItemUrl = $profileItem?->getUrl();

    $registrationItem = $items['register'] ?? null;
    $registrationItemUrl = $registrationItem?->getUrl();
    $isRegistrationItemVisible = $registrationItem?->isVisible() ?? true;
    $hasRegistrationItem = ((filament()->hasTenantRegistration() && filament()->getTenantRegistrationPage()::canView()) || filled($registrationItemUrl)) && $isRegistrationItemVisible;

    $canSwitchTenants = count($tenants = array_filter(
        filament()->getUserTenants(filament()->auth()->user()),
        fn (\Illuminate\Database\Eloquent\Model $tenant): bool => ! $tenant->is($currentTenant),
    ));

    $hasAccessToCreateTenant = auth()->user()->is_super_admin;
@endphp


@if($currentTenant)
    <x-panel-shift-dropdown.selected-tenant
        icon="heroicon-m-check"
        icon-color="primary"
        :url="filament()->getUrl($currentTenant)"
        :label="$currentTenantName"
    />
@endif

@foreach($companies as $tenant)
    <x-panel-shift-dropdown.item
        :url="filament()->getUrl($tenant)"
        :label="filament()->getTenantName($tenant)"
    />
@endforeach

@if($hasRegistrationItem and $hasAccessToCreateTenant)
    <x-panel-shift-dropdown.item
        :url="$registrationItemUrl ?? filament()->getTenantRegistrationUrl()"
        :label="$registrationItem?->getLabel() ?? filament()->getTenantRegistrationPage()::getLabel()"
        :icon="$registrationItem?->getIcon() ?? \Filament\Support\Facades\FilamentIcon::resolve('panels::tenant-menu.registration-button') ?? 'heroicon-m-plus'"
    />
@endif

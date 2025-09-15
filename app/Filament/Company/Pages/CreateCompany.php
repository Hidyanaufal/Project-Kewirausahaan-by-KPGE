<?php

namespace App\Filament\Company\Pages;

use App\Enums\Common\AddressType;
use App\Enums\Setting\EntityType;
use App\Models\Company;
use App\Models\Locale\Country;
use App\Models\Setting\Localization;
use App\Services\CompanyDefaultService;
use App\Utilities\Currency\CurrencyAccessor;
use DiogoGPinto\AuthUIEnhancer\Pages\Auth\Concerns\HasCustomLayout;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Wallo\FilamentCompanies\Events\AddingCompany;
use Wallo\FilamentCompanies\FilamentCompanies;
use Wallo\FilamentCompanies\Pages\Company\CreateCompany as FilamentCreateCompany;

class CreateCompany extends FilamentCreateCompany
{
    use HasCustomLayout;

    protected bool $hasTopbar = false;

    protected static string $view = 'filament.company.pages.create-company';

    public function getHeading(): string | Htmlable
    {
        return '';
    }

    public function getMaxWidth(): MaxWidth | string | null
    {
        return MaxWidth::FourExtraLarge;
    }

    public function hasLogo(): bool
    {
        return true;
    }

    public function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->schema([
                TextInput::make('name')
                    ->label('Company Name')
                    ->autofocus()
                    ->maxLength(255)
                    ->softRequired(),
                TextInput::make('profile.email')
                    ->label('Company Email')
                    ->autofocus()
                    ->maxLength(255)
                    ->softRequired(),
                Select::make('profile.entity_type')
                    ->hidden()
                    ->label('Entity type')
                    ->options(EntityType::class)
                    ->default(fn() => EntityType::LimitedPartnership)
                    ->softRequired(),
                Select::make('profile.country')
                    ->hidden()
                    ->label('Country')
                    ->live()
                    ->searchable()
                    ->options(Country::getAvailableCountryOptions())
                    ->getSearchResultsUsing(fn(string $search): array => Country::getSearchResultsUsing($search))
                    ->getOptionLabelUsing(fn($value): ?string => Country::find($value)?->name . ' ' . Country::find($value)?->flag)
                    ->default(fn() => 'ID')
                    ->softRequired(),
                Select::make('locale.language')
                    ->hidden()
                    ->label('Language')
                    ->searchable()
                    ->options(Localization::getAllLanguages())
                    ->default(fn() => 'en')
                    ->softRequired(),
                Select::make('currencies.code')
                    ->hidden()
                    ->label('Currency')
                    ->searchable()
                    ->options(CurrencyAccessor::getAllCurrencyOptions())
                    ->optionsLimit(5)
                    ->default(fn() => 'IDR')
                    ->softRequired(),
            ])
            ->model(FilamentCompanies::companyModel())
            ->statePath('data');
    }

    protected function handleRegistration(array $data): Model
    {
        $user = Auth::user();

        Gate::forUser($user)->authorize('create', FilamentCompanies::newCompanyModel());

        AddingCompany::dispatch($user);

        $personalCompany = $user?->personalCompany() === null;

        return DB::transaction(function () use ($user, $data, $personalCompany) {
            /** @var $data */
            $data['profile']['country'] = 'ID';
            $data['locale']['language'] = 'en';
            $data['currencies']['code'] = 'IDR';
            $data['profile']['entity_type'] = EntityType::LimitedPartnership;

            /** @var Company $company */
            $company = $user?->ownedCompanies()->create([
                'name' => $data['name'],
                'personal_company' => $personalCompany,
            ]);

            $profile = $company->profile()->create([
                'email' => $data['profile']['email'] ?? '',
                'entity_type' => $data['profile']['entity_type'],
            ]);

            $profile->address()->create([
                'company_id' => $company->id,
                'type' => AddressType::General,
                'country_code' => $data['profile']['country'],
            ]);

            $user?->switchCompany($company);

            $companyDefaultService = app(CompanyDefaultService::class);
            $user = $company->owner ?? $user;
            $companyDefaultService->createCompanyDefaults($company, $user, $data['currencies']['code'], $data['profile']['country'], $data['locale']['language']);

            $this->companyCreated($data['name']);

            return $company;
        });
    }
}

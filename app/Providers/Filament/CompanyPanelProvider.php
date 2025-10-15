<?php

namespace App\Providers\Filament;

use AchyutN\FilamentLogViewer\FilamentLogViewer;
use App\Actions\FilamentCompanies\AddCompanyEmployee;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use App\Actions\FilamentCompanies\CreateConnectedAccount;
use App\Actions\FilamentCompanies\CreateNewUser;
use App\Actions\FilamentCompanies\CreateUserFromProvider;
use App\Actions\FilamentCompanies\DeleteCompany;
use App\Actions\FilamentCompanies\DeleteUser;
use App\Actions\FilamentCompanies\HandleInvalidState;
use App\Actions\FilamentCompanies\InviteCompanyEmployee;
use App\Actions\FilamentCompanies\RemoveCompanyEmployee;
use App\Actions\FilamentCompanies\ResolveSocialiteUser;
use App\Actions\FilamentCompanies\SetUserPassword;
use App\Actions\FilamentCompanies\UpdateCompanyName;
use App\Actions\FilamentCompanies\UpdateConnectedAccount;
use App\Actions\FilamentCompanies\UpdateUserPassword;
use App\Actions\FilamentCompanies\UpdateUserProfileInformation;
use App\Filament\Company\Clusters\Settings;
use App\Filament\Company\Pages\Accounting\AccountChart;
use App\Filament\Company\Pages\CompanyDashboard;
use App\Filament\Company\Pages\CreateCompany;
use App\Filament\Company\Pages\ManageCompany;
use App\Filament\Company\Pages\Reports;
use App\Filament\Company\Pages\Service\ConnectedAccount;
use App\Filament\Company\Pages\Service\LiveCurrency;
use App\Filament\Company\Resources\Accounting\TransactionResource;
use App\Filament\Company\Resources\Addons\AccountDefaultResource;
use App\Filament\Company\Resources\Addons\AssetGroupResource;
use App\Filament\Company\Resources\Addons\AssetResource;
use App\Filament\Company\Resources\Addons\DepreciationRecordResource;
use App\Filament\Company\Resources\Addons\ProjectResource;
use App\Filament\Company\Resources\Addons\ProjectTypeResource;
use App\Filament\Company\Resources\Addons\RoleResource;
use App\Filament\Company\Resources\Addons\UserResource;
use App\Filament\Company\Resources\Banking\AccountResource;
use App\Filament\Company\Resources\Common\OfferingResource;
use App\Filament\Company\Resources\Purchases\BillResource;
use App\Filament\Company\Resources\Purchases\VendorResource;
use App\Filament\Company\Resources\Sales\ClientResource;
use App\Filament\Company\Resources\Sales\EstimateResource;
use App\Filament\Company\Resources\Sales\InvoiceResource;
use App\Filament\Company\Resources\Sales\RecurringInvoiceResource;
use App\Filament\Components\PanelShiftDropdown;
use App\Filament\Pages\Auth\Login;
use App\Filament\User\Clusters\Account;
use App\Helpers\NavigationHelper;
use App\Http\Middleware\ConfigureCurrentCompany;
use App\Livewire\UpdatePassword;
use App\Livewire\UpdateProfileInformation;
use App\Models\Company;
use App\Services\CompanySettingsService;
use App\Support\FilamentComponentConfigurator;
use DiogoGPinto\AuthUIEnhancer\AuthUIEnhancerPlugin;
use Exception;
use Filament\Actions;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Infolists\Components\TextEntry;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use pxlrbt\FilamentSpotlight\SpotlightPlugin;
use Rmsramos\Activitylog\ActivitylogPlugin;
use Wallo\FilamentCompanies\Actions\GenerateRedirectForProvider;
use Wallo\FilamentCompanies\FilamentCompanies;

class CompanyPanelProvider extends PanelProvider
{
    /**
     * @throws Exception
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->passwordReset()
            ->id('company')
            ->path('company')
            ->sidebarWidth('250px')
            ->login(Login::class)
            ->font(
                family: 'Inter',
                provider: GoogleFontProvider::class
            )
            ->spa(false)
            ->tenantMenu(false)
            ->plugins([
                ActivitylogPlugin::make(),
                SpotlightPlugin::make(),
                AuthUIEnhancerPlugin::make()
                    ->formPanelPosition('right')
                    ->emptyPanelBackgroundImageOpacity('60%')
                    ->emptyPanelBackgroundImageUrl('/image/backdrop.jpg')
                    ->showEmptyPanelOnMobile(),
                FilamentShieldPlugin::make(),
                FilamentCompanies::make()
                    ->userPanel('user')
                    ->switchCurrentCompany()
                    ->updateProfileInformation(component: UpdateProfileInformation::class)
                    ->updatePasswords(component: UpdatePassword::class)
                    ->setPasswords()
                    ->connectedAccounts()
                    ->manageBrowserSessions()
                    ->accountDeletion()
                    ->profilePhotos()
                    ->api()
                    ->companies(invitations: true)
                    ->autoAcceptInvitations()
                    ->termsAndPrivacyPolicy()
                    ->notifications()
                    ->modals(),
                PanelShiftDropdown::make()
                    ->logoutItem()
                    ->companySettings()
                    ->navigation(function (NavigationBuilder $builder): NavigationBuilder {
                        return $builder
                            ->items(Account::getNavigationItems());
                    }),
            ])
            ->colors([
                'primary' => Color::Green,
            ])
            ->navigation(function (NavigationBuilder $builder): NavigationBuilder {
                return $builder
                    ->items([
                        ...NavigationHelper::filterNavigationByPermissions(CompanyDashboard::class),
                        ...NavigationHelper::filterNavigationByPermissions(Reports::class),
                    ])
                    ->groups([
                        NavigationGroup::make('Projects')
                            ->label('Projects')
                            ->icon('heroicon-o-briefcase')
                            ->items([
                                ...NavigationHelper::filterNavigationByPermissions(ProjectTypeResource::class),
                                ...NavigationHelper::filterNavigationByPermissions(ProjectResource::class),
                            ]),

                        NavigationGroup::make('Sales')
                            ->label('Sales')
                            ->icon('heroicon-o-chart-bar')
                            ->items([
                                ...NavigationHelper::filterNavigationByPermissions(ClientResource::class),
                                ...NavigationHelper::filterNavigationByPermissions(EstimateResource::class),
                                ...NavigationHelper::filterNavigationByPermissions(InvoiceResource::class),
                            ]),

                        NavigationGroup::make('Purchases')
                            ->label('Purchases')
                            ->icon('heroicon-o-shopping-bag')
                            ->items([
                                ...NavigationHelper::filterNavigationByPermissions(BillResource::class),
                                ...NavigationHelper::filterNavigationByPermissions(VendorResource::class),
                            ]),

                        NavigationGroup::make('Accounting')
                            ->label('Accounting')
                            ->icon('heroicon-o-banknotes')
                            ->items([
                                ...NavigationHelper::filterNavigationByPermissions(AccountChart::class),
                                ...NavigationHelper::filterNavigationByPermissions(TransactionResource::class),
                                ...NavigationHelper::filterNavigationByPermissions(AccountDefaultResource::class),
                            ]),

                        NavigationGroup::make('Asset')
                            ->label('Asset')
                            ->icon('heroicon-o-presentation-chart-bar')
                            ->items([
                                ...NavigationHelper::filterNavigationByPermissions(AssetGroupResource::class),
                                ...NavigationHelper::filterNavigationByPermissions(AssetResource::class),
                                ...NavigationHelper::filterNavigationByPermissions(DepreciationRecordResource::class),
                            ]),

                        NavigationGroup::make('Banking')
                            ->label('Banking')
                            ->icon('heroicon-o-credit-card')
                            ->items([
                                ...NavigationHelper::filterNavigationByPermissions(AccountResource::class),
                            ]),

                        NavigationGroup::make('People')
                            ->label('People')
                            ->icon('heroicon-o-users')
                            ->items([
                                ...NavigationHelper::filterNavigationByPermissions(UserResource::class),
                                ...NavigationHelper::filterNavigationByPermissions(RoleResource::class),
                            ]),

                        NavigationGroup::make('Settings')
                            ->label('Settings')
                            ->icon('heroicon-o-cog-6-tooth')
                            ->items([
                                ...NavigationHelper::filterNavigationByPermissions(Settings::class),
                                ...NavigationHelper::filterNavigationByPermissions(OfferingResource::class),
                                ...NavigationHelper::filterNavigationByPermissions(ConnectedAccount::class),
                                ...NavigationHelper::filterNavigationByPermissions(LiveCurrency::class),
                            ]),
                    ]);
            })
            ->globalSearch(false)
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications(isLazy: false)
            ->viteTheme('resources/css/filament/company/theme.css')
            ->brandLogo('/image/system.logo.png')
            ->darkModeBrandLogo('/image/system.logo.alt.png')
            ->brandLogoHeight('100px')
            ->tenant(Company::class)
            ->tenantProfile(ManageCompany::class)
            ->tenantRegistration(CreateCompany::class)
            ->discoverResources(in: app_path('Filament/Company/Resources'), for: 'App\\Filament\\Company\\Resources')
            ->discoverPages(in: app_path('Filament/Company/Pages'), for: 'App\\Filament\\Company\\Pages')
            ->discoverClusters(in: app_path('Filament/Company/Clusters'), for: 'App\\Filament\\Company\\Clusters')
            ->authGuard('web')
            ->discoverWidgets(in: app_path('Filament/Company/Widgets'), for: 'App\\Filament\\Company\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->tenantMiddleware([
                ConfigureCurrentCompany::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePermissions();
        $this->configureDefaults();

        FilamentCompanies::createUsersUsing(CreateNewUser::class);
        FilamentCompanies::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        FilamentCompanies::updateUserPasswordsUsing(UpdateUserPassword::class);

        FilamentCompanies::createCompaniesUsing(CreateCompany::class);
        FilamentCompanies::updateCompanyNamesUsing(UpdateCompanyName::class);
        FilamentCompanies::addCompanyEmployeesUsing(AddCompanyEmployee::class);
        FilamentCompanies::inviteCompanyEmployeesUsing(InviteCompanyEmployee::class);
        FilamentCompanies::removeCompanyEmployeesUsing(RemoveCompanyEmployee::class);
        FilamentCompanies::deleteCompaniesUsing(DeleteCompany::class);
        FilamentCompanies::deleteUsersUsing(DeleteUser::class);

        FilamentCompanies::resolvesSocialiteUsersUsing(ResolveSocialiteUser::class);
        FilamentCompanies::createUsersFromProviderUsing(CreateUserFromProvider::class);
        FilamentCompanies::createConnectedAccountsUsing(CreateConnectedAccount::class);
        FilamentCompanies::updateConnectedAccountsUsing(UpdateConnectedAccount::class);
        FilamentCompanies::setUserPasswordsUsing(SetUserPassword::class);
        FilamentCompanies::handlesInvalidStateUsing(HandleInvalidState::class);
        FilamentCompanies::generatesProvidersRedirectsUsing(GenerateRedirectForProvider::class);
    }

    /**
     * Configure the roles and permissions that are available within the application.
     */
    protected function configurePermissions(): void
    {
        FilamentCompanies::defaultApiTokenPermissions(['read']);

        FilamentCompanies::role('admin', 'Administrator', [
            'create',
            'read',
            'update',
            'delete',
        ])->description('Administrator users can perform any action.');

        FilamentCompanies::role('editor', 'Editor', [
            'read',
            'create',
            'update',
        ])->description('Editor users have the ability to read, create, and update.');
    }

    /**
     * Configure the default settings for Filament.
     */
    protected function configureDefaults(): void
    {
        $this->configureSelect();

        Forms\Components\FileUpload::configureUsing(function (Forms\Components\FileUpload $component): void {
            $component
                ->hidden(is_demo_environment());
        });

        Actions\CreateAction::configureUsing(static fn(Actions\CreateAction $action) => FilamentComponentConfigurator::configureActionModals($action));
        Actions\EditAction::configureUsing(static fn(Actions\EditAction $action) => FilamentComponentConfigurator::configureActionModals($action));
        Actions\DeleteAction::configureUsing(static fn(Actions\DeleteAction $action) => FilamentComponentConfigurator::configureDeleteAction($action));
        Tables\Actions\EditAction::configureUsing(static fn(Tables\Actions\EditAction $action) => FilamentComponentConfigurator::configureActionModals($action));
        Tables\Actions\CreateAction::configureUsing(static fn(Tables\Actions\CreateAction $action) => FilamentComponentConfigurator::configureActionModals($action));
        Tables\Actions\DeleteAction::configureUsing(static fn(Tables\Actions\DeleteAction $action) => FilamentComponentConfigurator::configureDeleteAction($action));
        Tables\Actions\DeleteBulkAction::configureUsing(static fn(Tables\Actions\DeleteBulkAction $action) => FilamentComponentConfigurator::configureDeleteAction($action));

        Tables\Table::configureUsing(static function (Tables\Table $table): void {
            $table::$defaultDateDisplayFormat = CompanySettingsService::getDefaultDateFormat();
            $table::$defaultTimeDisplayFormat = CompanySettingsService::getDefaultTimeFormat();
            $table::$defaultDateTimeDisplayFormat = CompanySettingsService::getDefaultDateTimeFormat();

            $table
                ->paginationPageOptions([5, 10, 25, 50, 100])
                ->filtersFormWidth(MaxWidth::Small)
                ->filtersTriggerAction(
                    fn(Tables\Actions\Action $action) => $action
                        ->button()
                        ->label('Filters')
                        ->slideOver()
                );
        });

        Tables\Columns\TextColumn::configureUsing(function (Tables\Columns\TextColumn $column): void {
            $column->placeholder('–');
        });

        TextEntry::configureUsing(function (TextEntry $component): void {
            $component->placeholder('–');
        });

        Tables\Actions\ExportAction::configureUsing(function (Tables\Actions\ExportAction $action) {
            $action
                ->color('primary')
                ->slideOver();
        });
    }

    /**
     * Configure the default settings for the Select component.
     */
    protected function configureSelect(): void
    {
        Select::configureUsing(function (Select $select): void {
            $select
                ->native(false)
                ->selectablePlaceholder(fn(Select $component) => ! $component->isRequired());
        });
    }
}

<?php

namespace App\Filament\Company\Resources\Addons\UserResource\Pages;

use App\Filament\Company\Resources\Addons\UserResource;
use App\Helpers\AuthHelper;
use App\Models\User;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\DB;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->modalWidth(MaxWidth::Medium)
                ->action(function (array $data) {
                    DB::beginTransaction();
                    try {
                        $data['company_id'] = AuthHelper::currentCompany();
                        $data['current_company_id'] = AuthHelper::currentCompany();
                        $user = relation_create(
                            root: User::class,
                            data: $data,
                        );
                        DB::table('company_user')->insert([
                            'company_id' => AuthHelper::currentCompany(),
                            'user_id' => $user->id,
                        ]);
                        if (isset($data['roles'])) {
                            foreach ($data['roles'] as $role) {
                                DB::table('model_has_roles')->insert([
                                    'model_type' => User::class,
                                    'model_id' => $user->id,
                                    'role_id' => $role,
                                    'company_id' => AuthHelper::currentCompany()
                                ]);
                            }
                        }
                        DB::commit();
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Notification::make()
                            ->danger()
                            ->title('An error occurred')
                            ->body($e->getMessage())
                            ->send();
                    }
                })
                ->slideOver(),
        ];
    }
}
